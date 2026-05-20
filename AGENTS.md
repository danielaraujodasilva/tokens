# AGENTS.md

## Objetivo principal

Trabalhe neste repositório de forma econômica, cirúrgica e segura.

Este repositório contém o app `Token Miser` e o webhook de deploy. Não trate o repositório inteiro como uma única área de mudança se a tarefa pedir só uma parte.

## Regra de economia de tokens

- Não leia o projeto inteiro sem necessidade.
- Não faça buscas amplas se a tarefa indicar arquivos, pastas ou trechos específicos.
- Antes de abrir vários arquivos, identifique o menor conjunto provável de arquivos envolvidos.
- Prefira alterações pequenas, diretas e localizadas.
- Não produza explicações longas ao final.
- Ao terminar, responda apenas:
  1. arquivos alterados
  2. resumo curto do que mudou
  3. como testar

## Escopo de alteração

- Altere somente os arquivos necessários para a tarefa.
- Não modifique arquivos fora do escopo sem justificar antes.
- Não refatore código que não foi pedido.
- Não reorganize pastas.
- Não renomeie funções, variáveis, rotas ou arquivos existentes sem necessidade real.
- Não altere layout, estilos ou comportamento fora do que foi solicitado.
- Não crie dependências novas se a solução puder ser feita com o que já existe.

## Estilo de implementação

- Faça o menor diff possível.
- Preserve o padrão visual e estrutural já existente.
- Em PHP, mantenha compatibilidade com servidor XAMPP/Apache/MySQL.
- Em JavaScript/Node, preserve a estrutura atual e evite trocar bibliotecas.
- Para banco de dados, nunca assuma que pode apagar ou recriar tabelas.

## Antes de alterar

Para tarefas médias ou grandes:

1. Liste os arquivos que pretende tocar.
2. Explique o plano em no máximo 5 linhas.
3. Só depois aplique a alteração.

Para tarefas pequenas e bem específicas, pode aplicar direto.

## Testes

- Quando possível, informe um teste manual simples.
- Não rode comandos destrutivos.
- Não delete dados.
- Não sobrescreva arquivos grandes sem necessidade.

## Segurança

- Nunca exponha secrets, tokens, senhas, chaves de API ou credenciais.
- Não commite arquivos `.env`, dumps de banco ou backups sensíveis.
- Se encontrar segredo no código, avise no resumo.

## Quando estiver em dúvida

- Não chute.
- Não faça melhorias não solicitadas.
- Prefira perguntar ou parar com uma explicação curta.
- Se houver múltiplas opções, escolha a mais simples e reversível.
