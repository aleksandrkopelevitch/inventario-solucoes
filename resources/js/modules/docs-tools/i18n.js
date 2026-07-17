// PT-BR translation for Editor.js — NO external package: the core
// (@editorjs/editorjs 2.31) already ships built-in i18n via the
// `i18n.messages` option. This dictionary is applied to the main editor
// (docs-editor.js) and passed down to the nested tab editors
// (docs-tools/tabs.js), so the whole UI — toolbox, block search, settings
// menu (block tunes), inline toolbar and each tool's menus (image, table,
// list, file) — shows up in Portuguese.
//
// Strings from OUR OWN tools (Hint, Tabs) are already authored in PT-BR in
// the tool itself; here we only translate what ships in English from
// third-party packages and the core. The English keys on the left are the
// source strings Editor.js uses as the translation identifier — don't invent
// new keys, they need to match the original text of each tool/namespace
// exactly.
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
        // Block names in the toolbox and inline tool names. The keys are each
        // package's `toolbox.title` (and inline tool names) — includes our
        // own: 'Tabs' and 'Hint'.
        toolNames: {
            Text: 'Texto',
            Heading: 'Título',
            // @editorjs/list (v2) registers three separate toolbox entries,
            // each with its own title (not just "List").
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
        // Internal messages for each tool. The outer key is the tool's NAME as
        // registered in the `tools` map (docs-editor.js): image, table, list…
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
        // Default core block tunes. "Move" stays hidden via CSS
        // (docs-editor.css), but we translate it for consistency.
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
