# Rollout de produção — AWS ECS Fargate

Checklist pra primeira subida da infra. Exige credenciais AWS reais
configuradas (`aws configure` ou variáveis de ambiente) e acesso ao
painel de DNS de `gespriority.com.br`.

1. **Criar o bucket de state do Terraform** (uma vez, via AWS CLI — resolve o problema de "ovo e galinha" do backend remoto):

   ```bash
   aws s3api create-bucket --bucket gespriority-itsm-tfstate --region us-east-1
   aws s3api put-bucket-versioning --bucket gespriority-itsm-tfstate --versioning-configuration Status=Enabled
   aws s3api put-public-access-block --bucket gespriority-itsm-tfstate \
     --public-access-block-configuration BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true
   cd infra/terraform
   terraform init
   ```

2. **Aplicar rede, banco e ECR primeiro** (a task definition do ECS
   referencia uma imagem que ainda não existe — precisa existir antes
   do apply completo):

   ```bash
   cd infra/terraform
   export TF_VAR_app_key=$(cd ../.. && php artisan key:generate --show)
   echo "Guarde este valor com segurança — toda vez que rodar apply de novo, reutilize o mesmo TF_VAR_app_key (uma chave diferente invalida sessões e dados criptografados em produção): $TF_VAR_app_key"
   terraform apply -target=module.network -target=module.data -target=module.ecr
   ```

3. **Buildar e publicar a primeira imagem** (tag `bootstrap`, a mesma
   do default de `var.image_tag`):

   ```bash
   cd ../..
   aws ecr get-login-password --region us-east-1 | \
     docker login --username AWS --password-stdin "$(terraform -chdir=infra/terraform output -raw ecr_repository_url | cut -d/ -f1)"
   docker build -f docker/php/Dockerfile.prod -t "$(terraform -chdir=infra/terraform output -raw ecr_repository_url):bootstrap" .
   docker push "$(terraform -chdir=infra/terraform output -raw ecr_repository_url):bootstrap"
   ```

4. **Aplicar o resto da infra** (cria ECS, ALB, certificado ACM, role
   do GitHub Actions):

   ```bash
   cd infra/terraform
   terraform apply
   ```

   Reutiliza o `TF_VAR_app_key` exportado no passo 2 (se estiver em um
   terminal novo, reexporte a mesma variável com o mesmo valor antes de
   rodar o apply — nunca gere uma chave nova aqui, ou a chave em
   produção mudará e invalidará sessões e dados criptografados
   existentes).

   O apply vai pausar em `aws_acm_certificate_validation` esperando o
   certificado validar. Nesse momento, rodar `terraform output
   acm_validation_records` em outro terminal e cadastrar o(s) registro(s)
   CNAME retornado(s) no DNS atual de `gespriority.com.br` (fora da
   Route53). Depois que o DNS propagar (minutos a poucas horas,
   dependendo do TTL), o apply retoma sozinho.

5. **Configurar os GitHub Secrets** do repositório
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

6. **Apontar o DNS de produção**: criar um registro `CNAME` (ou `ALIAS`,
   se o provedor de DNS suportar) de `gespriority.com.br` pro valor de
   `terraform -chdir=infra/terraform output -raw alb_dns_name`.

7. **Validar a aplicação antes de depender do pipeline**: rodar
   `curl -I https://gespriority.com.br/up` e confirmar `200 OK`. Testar
   upload/download/delete de um anexo real (rota
   `/api/incidentes/{id}/anexos`) pra confirmar que o S3 está
   funcionando fim a fim.

8. **Disparar o pipeline de verdade**: dar `git push` na `main` (mesmo
   que um commit trivial) e acompanhar a run do workflow `deploy.yml`
   no GitHub Actions até `Wait for services to stabilize` passar.

9. **Rollback, se precisar**: reverter é apontar o service de volta pra
   revisão anterior da task definition:

   ```bash
   aws ecs update-service --cluster gespriority-itsm-cluster --service gespriority-itsm-web \
     --task-definition gespriority-itsm-web:<revisão anterior>
   ```
