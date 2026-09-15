<?php

namespace App\Support\Documentation;

use App\Models\Diagram;
use App\Models\Notebook;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serving the picture of a diagram a caderno CITES.
 *
 * Both read-only surfaces need this and neither may use `diagrams.picture.show`
 * for it, for two different reasons that land in the same place: a magic-link
 * visitor has no account and would be redirected to the login screen, and a
 * `/docs` reader has an account whose tier may not reach the canvas at all.
 * Either way a citation would render as a broken image.
 *
 * **Authorised by CITATION, not by the diagram.** What a reader may see is what
 * the caderno in front of them cites, so a drawing nobody cited here is a 404
 * even on a perfectly valid surface — which is what stops the route being
 * walked to enumerate the diagram catalog. The caller has already established
 * that this reader may read THIS caderno (a token that resolved, or a caderno
 * that is published); this decides the rest.
 */
final class DiagramCitation
{
    /** Whether any page of `$notebook` cites `$diagram`. */
    public static function cited(Notebook $notebook, Diagram $diagram): bool
    {
        // `\` and `%`/`_` escaped: a slug never contains them today (Str::slug
        // emits neither), but a LIKE pattern built from data is not the place to
        // rely on that staying true.
        //
        // Deliberately NOT `whereFolded()`, which every search in the app uses:
        // this is not a search. It asks whether this caderno cites this exact
        // slug, and it is the whole authorisation for serving the picture — a
        // comparison that ignores case and accents would let `SLUG-A` stand in
        // for `slug-a` and widen what one reader reaches.
        $needle = addcslashes('diagram slug="' . $diagram->slug . '"', '\\%_');

        return $notebook->pages()->where('documentation', 'like', '%' . $needle . '%')->exists();
    }

    /** The drawing's current PNG, or a 404 when it is uncited or has none. */
    public static function stream(Notebook $notebook, Diagram $diagram): StreamedResponse
    {
        abort_unless(self::cited($notebook, $diagram), 404);

        $media = $diagram->picture();

        abort_if($media === null, 404);

        return response()->stream(function () use ($media) {
            readfile($media->getPath());
        }, 200, [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }
}
