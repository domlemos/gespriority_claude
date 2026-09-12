# Role de deploy do backend pra infra2 (EC2 via SSM), equivalente ao
# module.frontend.github_actions_role_arn mas pro backend — mesmo padrão:
# reaproveita o OIDC provider do GitHub já existente na conta (criado por
# infra/terraform/modules/cicd, mantido mesmo com o ECS descomissionado
# porque o módulo frontend também depende dele), sem criar um segundo.
#
# Sem ECR/ECS aqui: o deploy não empurra imagem pra lugar nenhum, só manda
# a instância se atualizar sozinha (`git pull` + `docker compose up -d
# --build`) via `ssm:SendCommand` — ver .github/workflows/deploy-ec2.yml.
data "aws_iam_openid_connect_provider" "github" {
  url = "https://token.actions.githubusercontent.com"
}

data "aws_region" "current" {}

data "aws_iam_policy_document" "github_assume" {
  statement {
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [data.aws_iam_openid_connect_provider.github.arn]
    }

    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    # Mesmo racional de infra/terraform/modules/cicd: casa as duas formas
    # do sub claim (com e sem os IDs numéricos que o GitHub embute por
    # padrão em repos/orgs novos), pra não quebrar se esse default mudar.
    condition {
      test     = "StringLike"
      variable = "token.actions.githubusercontent.com:sub"
      values = [
        "repo:${var.backend_github_repository}:ref:refs/heads/main",
        "repo:${split("/", var.backend_github_repository)[0]}@*/${split("/", var.backend_github_repository)[1]}@*:ref:refs/heads/main",
      ]
    }
  }
}

resource "aws_iam_role" "github_actions" {
  name               = "${var.project_name}-ec2-github-actions"
  assume_role_policy = data.aws_iam_policy_document.github_assume.json
}

data "aws_iam_policy_document" "github_actions" {
  statement {
    sid     = "SsmSendCommand"
    actions = ["ssm:SendCommand"]
    resources = [
      var.instance_arn,
      "arn:aws:ssm:${data.aws_region.current.name}::document/AWS-RunShellScript",
    ]
  }

  # GetCommandInvocation/List* não suportam restrição por ARN de recurso
  # (só "*") — é assim que o pipeline lê o resultado do comando disparado
  # acima pra saber se o deploy passou ou falhou.
  statement {
    sid       = "SsmReadCommandResult"
    actions   = ["ssm:GetCommandInvocation", "ssm:ListCommandInvocations", "ssm:ListCommands"]
    resources = ["*"]
  }
}

resource "aws_iam_role_policy" "github_actions" {
  name   = "${var.project_name}-ec2-github-actions"
  role   = aws_iam_role.github_actions.id
  policy = data.aws_iam_policy_document.github_actions.json
}
