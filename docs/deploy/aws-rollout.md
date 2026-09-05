# Rollout de produção — AWS ECS Fargate

Checklist pra primeira subida da infra. Exige credenciais AWS reais
configuradas (`aws configure` ou variáveis de ambiente) e acesso ao
painel do registro.br pra delegar os *name servers* de
`gespriority.com.br` pro Route53 (a zona é criada pelo próprio
Terraform, no passo 2).

1. **Criar o bucket de state do Terraform** (uma vez, via AWS CLI — resolve o problema de "ovo e galinha" do backend remoto):

   ```bash
   aws s3api create-bucket --bucket gespriority-itsm-tfstate --region us-east-1
   aws s3api put-bucket-versioning --bucket gespriority-itsm-tfstate --versioning-configuration Status=Enabled
   aws s3api put-public-access-block --bucket gespriority-itsm-tfstate \
     --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
   cd infra/terraform
   terraform init
   ```

2. **Aplicar rede, banco, ECR e a zona Route53 primeiro** (a task
   definition do ECS referencia uma imagem que ainda não existe — precisa
   existir antes do apply completo; e a validação do certificado ACM,
   no passo 5, só funciona depois que a zona estiver delegada):

   ```bash
   cd infra/terraform
   export TF_VAR_app_key=$(cd ../.. && php artisan key:generate --show)
   echo "Guarde este valor com segurança — toda vez que rodar apply de novo, reutilize o mesmo TF_VAR_app_key (uma chave diferente invalida sessões e dados criptografados em produção): $TF_VAR_app_key"
   terraform apply -target=module.network -target=module.data -target=module.ecr -target=module.ecs.aws_route53_zone.this
   ```

3. **Delegar o domínio pro Route53**: pegar os *name servers* da zona
   recém-criada e cadastrá-los no painel do registro.br (Meus Domínios →
   `gespriority.com.br` → Alterar servidores DNS):

   ```bash
   terraform output -json route53_name_servers
   ```

   Se o registro.br mostrar um aviso de "servidores DNS em transição",
   é uma carência de segurança do próprio registro.br antes de permitir
   trocar os NS — espere o tempo indicado antes de conseguir salvar a
   delegação. Depois de salvar, a propagação pode levar de minutos a
   algumas horas; confirme com `dig NS gespriority.com.br +short` até
   aparecerem os *name servers* da AWS antes de seguir pro passo 5.

4. **Buildar e publicar a primeira imagem** (tag `bootstrap`, a mesma
   do default de `var.image_tag`):

   ```bash
   cd ../..
   aws ecr get-login-password --region us-east-1 | \
     docker login --username AWS --password-stdin "$(terraform -chdir=infra/terraform output -raw ecr_repository_url | cut -d/ -f1)"
   docker build -f docker/php/Dockerfile.prod -t "$(terraform -chdir=infra/terraform output -raw ecr_repository_url):bootstrap" .
   docker push "$(terraform -chdir=infra/terraform output -raw ecr_repository_url):bootstrap"
   ```

5. **Aplicar o resto da infra** (cria ECS, ALB, certificado ACM já
   validado automaticamente via Route53, o registro que aponta
   `gespriority.com.br` pro Load Balancer, e a role do GitHub Actions):

   ```bash
   cd infra/terraform
   terraform apply
   ```

   Reutiliza o `TF_VAR_app_key` exportado no passo 2 (se estiver em um
   terminal novo, reexporte a mesma variável com o mesmo valor antes de
   rodar o apply — nunca gere uma chave nova aqui, ou a chave em
   produção mudará e invalidará sessões e dados criptografados
   existentes).

   A validação do certificado ACM e o apontamento de DNS pro ALB agora
   são automáticos (o Terraform cria os registros dentro da zona
   Route53 sozinho) — só funcionam se a delegação do passo 3 já tiver
   propagado. Se o apply travar em `aws_acm_certificate_validation`
   por mais de alguns minutos, confirme a propagação com `dig NS
   gespriority.com.br +short` antes de esperar mais.

6. **Configurar os GitHub Secrets** do repositório
   `domlemos/gespriority_claude` (Settings → Secrets and variables →
   Actions), usando os outputs do Terraform:

   ```bash
   terraform -chdir=infra/terraform output -raw github_actions_role_arn
   terraform -chdir=infra/terraform output -json private_subnet_ids
   terraform -chdir=infra/terraform output -raw ecs_tasks_security_group_id
   ```

   - `AWS_GITHUB_ACTIONS_ROLE_ARN` = o ARN da role.
   - `PRIVATE_SUBNET_IDS` = as subnet ids separadas por vírgula, sem
     colchetes nem aspas (ex.: `subnet-abc123,subnet-def456`).
   - `ECS_TASKS_SECURITY_GROUP_ID` = o id do security group.

7. **Validar a aplicação antes de depender do pipeline**: rodar
   `curl -I https://gespriority.com.br/up` e confirmar `200 OK`. Testar
   upload/download/delete de um anexo real (rota
   `/api/incidentes/{id}/anexos`) pra confirmar que o S3 está
   funcionando fim a fim.

8. **Sair do sandbox do SES**: toda conta SES nova nasce em modo
   sandbox — só envia e-mail pra endereços/domínios verificados
   manualmente no console, com limite baixo de volume. Isso não dá pra
   automatizar via Terraform; é um pedido manual em SES → Account
   dashboard → "Request production access" (ou um ticket de suporte),
   normalmente aprovado em algumas horas. Enquanto estiver em sandbox,
   verifique manualmente pelo menos um endereço de teste (SES →
   Verified identities → Create identity) pra conseguir validar o
   envio antes da aprovação sair.

9. **Disparar o pipeline de verdade**: dar `git push` na `main` (mesmo
   que um commit trivial) e acompanhar a run do workflow `deploy.yml`
   no GitHub Actions até `Wait for services to stabilize` passar.

10. **Rollback, se precisar**: reverter é apontar o service de volta pra
    revisão anterior da task definition:

    ```bash
    aws ecs update-service --cluster gespriority-itsm-cluster --service gespriority-itsm-web \
      --task-definition gespriority-itsm-web:<revisão anterior>
    ```
