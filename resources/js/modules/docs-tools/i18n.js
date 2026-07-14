// Tradução PT-BR do Editor.js — SEM pacote externo: o core (@editorjs/editorjs
// 2.31) já traz i18n embutido via a opção `i18n.messages`. Este dicionário é
// aplicado no editor principal (docs-editor.js) e repassado aos editores
// aninhados das abas (docs-tools/tabs.js), então toda a UI — toolbox, busca de
// blocos, menu de ajustes (block tunes), toolbar inline e os menus de cada tool
// (imagem, tabela, lista, arquivo) — aparece em português.
//
// As strings dos NOSSOS tools (Hint, Tabs) já nascem em PT-BR no próprio tool;
// aqui traduzimos só o que vem em inglês dos pacotes de terceiros e do core.
// As chaves em inglês à esquerda são as strings-fonte que o Editor.js usa como
// identificador de tradução — não invente chaves novas, elas precisam bater
// exatamente com o texto original de cada tool/namespace.
export const EDITOR_I18N = {
    messages: {
        ui: {
            blockTunes: {
                toggler: {
                    'Click to tune': 'Clique para ajustar',
                    'or drag to move': 'ou arraste para mover',
                },
            },
            inlineToolbar: {
                converter: {
                    'Convert to': 'Converter em',
                },
            },
            toolbar: {
                toolbox: {
                    Add: 'Adicionar',
                },
            },
            popover: {
                Filter: 'Buscar',
                'Nothing found': 'Nada encontrado',
                'Convert to': 'Converter em',
            },
        },
        // Nomes dos blocos na toolbox e das ferramentas inline. As chaves são os
        // `toolbox.title` (e nomes das inline tools) de cada pacote — inclui os
        // nossos: 'Tabs' e 'Hint'.
        toolNames: {
            Text: 'Texto',
            Heading: 'Título',
            // O @editorjs/list (v2) registra três entradas separadas na toolbox,
            // cada uma com seu próprio título (não é só "List").
            List: 'Lista',
            'Unordered List': 'Lista com marcadores',
            'Ordered List': 'Lista numerada',
            Checklist: 'Lista de tarefas',
            Quote: 'Citação',
            Code: 'Código',
            Delimiter: 'Divisor',
            Table: 'Tabela',
            Image: 'Imagem',
            Attachment: 'Arquivo',
            Bold: 'Negrito',
            Italic: 'Itálico',
            Link: 'Link',
            Marker: 'Marca-texto',
            InlineCode: 'Código em linha',
            Tabs: 'Abas',
            Hint: 'Aviso',
        },
        // Mensagens internas de cada tool. A chave externa é o NOME do tool como
        // registrado no mapa `tools` (docs-editor.js): image, table, list…
        tools: {
            list: {
                Ordered: 'Numerada',
                Unordered: 'Com marcadores',
                Checklist: 'Lista de tarefas',
            },
            image: {
                Caption: 'Legenda',
                'Select an Image': 'Selecionar imagem',
                'With border': 'Com borda',
                'Stretch image': 'Esticar imagem',
                'With background': 'Com fundo',
            },
            attaches: {
                'File title': 'Nome do arquivo',
                'Select file': 'Selecionar arquivo',
                'Select File': 'Selecionar arquivo',
            },
            table: {
                Heading: 'Cabeçalho',
                'Add column to left': 'Adicionar coluna à esquerda',
                'Add column to right': 'Adicionar coluna à direita',
                'Delete column': 'Excluir coluna',
                'Add row above': 'Adicionar linha acima',
                'Add row below': 'Adicionar linha abaixo',
                'Delete row': 'Excluir linha',
                'With headings': 'Com cabeçalho',
                'Without headings': 'Sem cabeçalho',
            },
            link: {
                'Add a link': 'Adicionar um link',
            },
            code: {
                'Enter a code': 'Digite o código',
            },
            quote: {
                "Enter a quote": 'Digite a citação',
                "Enter a caption": 'Digite o autor/fonte',
            },
        },
        // Ajustes de bloco padrão do core. "Mover" fica escondido via CSS
        // (docs-editor.css), mas traduzimos por consistência.
        blockTunes: {
            delete: {
                Delete: 'Excluir',
                'Click to delete': 'Clique para excluir',
            },
            moveUp: {
                'Move up': 'Mover para cima',
            },
            moveDown: {
                'Move down': 'Mover para baixo',
            },
        },
    },
}
