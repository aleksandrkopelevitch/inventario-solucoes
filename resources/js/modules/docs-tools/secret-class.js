// The class name the inline "Valor protegido" tool marks text with — shared by
// the tool itself (docs-tools/secret.js) and by the Markdown round trip
// (docs-markdown.js), which has to recognise the span to write
// {% secret %}…{% endsecret %} back out.
//
// A module of its own for the same reason `hint-icons.js` is one: docs-editor.js
// imports the TOOL dynamically (it belongs in the Editor.js chunk, loaded only
// where there is an editor) and docs-markdown.js imports it statically, and a
// module pulled in both ways cannot be split out — Rolldown says so with
// INEFFECTIVE_DYNAMIC_IMPORT and quietly drags the tool into the main bundle. A
// bare constant is free to live in both.
export const SECRET_CLASS = 'ak-secret-mark'
