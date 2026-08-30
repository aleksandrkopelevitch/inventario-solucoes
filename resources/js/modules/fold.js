// fold.js — the client-side half of App\Support\Fold.
//
// Every filter in this app that narrows a list IN THE BROWSER (the flowSpec
// document picker, the diagram picker, the ecosystem map's search) compares
// what somebody typed against text somebody else wrote, and has to be as
// forgiving about it as the searches that go to the database: "big" finds
// "Google BigQuery", "integracao" finds "Integração", and so does "integração"
// against a name written without accents.
//
// `toLowerCase()` alone — which is what all three used to do — answers the
// first of those and neither of the others.
//
// NFD splits an accented letter into its base plus a combining mark, and the
// range below is exactly those marks. Unlike the PHP side this is NOT
// character-count-preserving, which is fine here: these filters ask
// `includes()` and never highlight by offset.

const COMBINING_MARKS = /[\u0300-\u036f]/g

/** Lowercased and accent-folded, for comparison only — never for display. */
export function fold(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(COMBINING_MARKS, '')
        .toLowerCase()
}
