# Boas-vindas à base de conhecimento

Esta é a documentação que o time de Arquitetura da Leo Madeiras mantém sobre os nossos sistemas, as integrações entre eles e os processos que dependem deles — escrita uma vez, num lugar só, e lida por quem precisa dela.

{{figure:base-escrever-publicar-ler.svg|Do editor para toda a empresa: o time de Arquitetura escreve, um administrador publica e o caderno aparece aqui.}}

## Para que serve

- **Encontrar como as coisas funcionam** sem depender de quem as construiu: o que cada sistema faz, com quem ele conversa e o que fazer quando algo para.
- **Uma fonte confiável.** Só aparece aqui o que um administrador decidiu publicar. Rascunhos e cadernos em construção continuam no Inventário, fora da vista.
- **Para todo mundo da Leo.** Basta entrar com a sua conta Leo Madeiras — não é preciso pedir acesso caderno por caderno.

## Cadernos

Toda a documentação está organizada em **cadernos**. Cada caderno reúne as páginas sobre um assunto — um sistema, uma integração, um processo — em uma árvore de páginas e sub-páginas, como os capítulos de um manual.

Um caderno pode descrever **várias soluções do catálogo** ao mesmo tempo: a integração entre o SAP e a VTEX, por exemplo, é um texto só, lido pelos dois lados.

{{figure:base-cadernos.svg|Um caderno liga-se às soluções que descreve e se organiza em páginas e sub-páginas.}}

{% hint style="success" %}
Use o campo **Filtrar cadernos** no menu ao lado. Ele procura pelo nome do caderno **e** pelo nome dos sistemas que ele descreve — digite "Digibee" ou "SAP" e tecle **Enter** para abrir o primeiro resultado.
{% endhint %}

## Busca dentro do caderno

Dentro de um caderno, a busca fica no alto da tela (ou em **⌘K** / **Ctrl+K**). Ela procura em todas as páginas de uma vez e leva direto à **seção** onde o termo aparece, com o trecho destacado. Dá para escolher onde procurar: no texto, nas tabelas ou nos blocos de código.

{{figure:base-busca.svg|A busca devolve seções, não só páginas — e mostra o trecho em que o termo foi encontrado.}}

## Diagramas

Muitas páginas trazem **diagramas de topologia**: quais sistemas participam de um fluxo, como se conectam e em que ordem. Eles são desenhados no canvas do Inventário, a partir dos sistemas do catálogo, e a página mostra sempre a **versão atual** do desenho — quando o diagrama muda, a documentação muda junto.

{{figure:base-diagramas.svg|Uma página citando um diagrama: o desenho é o mesmo que o time mantém no canvas.}}

## Informações protegidas

Alguns valores — uma senha de serviço, um token, um endereço interno — aparecem com um **cadeado**. Para ver o valor, clique no cadeado e informe o **código do caderno**, que o time de Arquitetura envia a quem precisa dele. O valor é revelado só para você, só naquela tela, e nunca vai junto quando o texto é copiado.

{{figure:base-valor-protegido.svg|Um valor protegido continua mascarado até que alguém informe o código do caderno.}}

{% hint style="warning" %}
São cinco tentativas a cada 12 horas. Se o código não funcionar, peça um novo ao time de Arquitetura — ele pode ter sido trocado.
{% endhint %}

## Pergunte ao Claude

A base também pode ser consultada **conversando com o Claude**. Conecte o Inventário ao Claude em [Conectar um programa](/mcp/connect) e pergunte em linguagem natural — "como o pedido chega ao SAP?", "o que fazer quando a integração fiscal falha?". As respostas citam o caderno e a página de onde vieram.

{{figure:base-claude.svg|O Claude responde a partir dos cadernos publicados e diz de qual caderno tirou a resposta.}}

A conexão é **somente leitura** e enxerga apenas os cadernos publicados aqui; valores protegidos nunca saem.

## Como navegar

| Onde | O que você encontra |
| --- | --- |
| Menu à esquerda | Nesta tela, todos os cadernos publicados. Dentro de um caderno, o índice das páginas dele. |
| Nome do caderno, no alto | Troca de caderno sem voltar para esta página. |
| Nesta página, à direita | Os títulos da página aberta, para pular direto a uma seção. |
| Fim de cada página | As sub-páginas, quando a página abre uma seção. |

## Encontrou algo errado ou desatualizado?

Avise o time de Arquitetura. A documentação é corrigida no mesmo editor em que foi escrita, e a correção aparece aqui assim que é salva.
