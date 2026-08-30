<?php

namespace App\Actions\Documentation;

use App\Actions\Flowspec\NormalizeReferenceFlowspec;
use App\Models\Notebook;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Turns a long paste in the Assiste IA composer into a context document of the
 * caderno — the behavior the Especialista em Integrações already had
 * (App\Actions\Flowspec\AttachFlowspecText), arriving here because the material
 * people bring to a documentation conversation is the same material: a pipeline
 * JSON, a spec, a chunk of a ticket, none of which belongs in a message body.
 *
 * Where it LANDS is the one real difference between the two assistants, and it
 * follows their existing designs rather than flattening them. F8's context is
 * chat-scoped, so a paste there is a row on the chat. A caderno's context
 * documents are the notebook's and are shared by every page in it, so a paste
 * here becomes one of those: it survives the conversation, and it is available
 * while documenting the next page of the same caderno, which is usually the
 * point. It is removable from the same list it appears in.
 *
 * A pasted PIPELINE is minified on the way in (the top-level `meta` is the
 * canvas position map, worth nothing to a reader and roughly half the bytes).
 * That is not a nicety here: ContextDocumentResolver TRUNCATES a text document
 * past `doc_budget_chars`, and a JSON truncated mid-object is unparseable for
 * the model that receives it. Halving it is often the difference between the
 * whole pipeline arriving and a broken fragment arriving.
 */
class AttachContextText
{
    public function __construct(private readonly NormalizeReferenceFlowspec $normalize) {}

    public function handle(Notebook $notebook, string $text): Media
    {
        $text = trim($text);
        $isFlowspec = NormalizeReferenceFlowspec::looksLike($text);
        $content = $isFlowspec ? $this->normalize->handle($text) : $text;

        $name = $this->uniqueName($notebook, $this->baseName($text, $isFlowspec));

        // A pipeline is stored as `.json` and prose as `.txt` so that
        // ContextDocumentResolver reads both back as text (both extensions are
        // in its TEXT_EXTENSIONS) and a person downloading one gets a file their
        // editor opens correctly.
        return $notebook
            ->addMediaFromString($content)
            ->usingFileName($name . ($isFlowspec ? '.json' : '.txt'))
            ->usingName($name)
            ->toMediaCollection(Notebook::CONTEXT_COLLECTION);
    }

    /**
     * A pasted pipeline has no name to read — `{meta, flowSpec}` carries none —
     * so it gets a constant, made unique below. Anything else is named after
     * its own first line, which is what a person recognizes it by.
     *
     * The name is derived HERE rather than sent by the composer (which is what
     * the F8 paste does): the server is already the one that has to recognize a
     * pipeline to minify it, and a client-supplied name would just be a second
     * chance to disagree about what the thing is called.
     */
    private function baseName(string $text, bool $isFlowspec): string
    {
        if ($isFlowspec) {
            return 'flowspec-colado';
        }

        $slug = Str::slug(Str::limit(trim((string) Str::before($text, "\n")), 60, ''));

        // A paste whose first line is punctuation or another alphabet slugs to
        // nothing, and an empty file name is not a name.
        return $slug === '' ? 'texto-colado' : $slug;
    }

    /**
     * Keeps the name unique within the caderno's context collection.
     *
     * The list is a column of checkboxes read by file name; two identically
     * named documents there are two rows nobody can choose between — the exact
     * complaint that started this, seen from the other assistant.
     */
    private function uniqueName(Notebook $notebook, string $base): string
    {
        $taken = $notebook->getMedia(Notebook::CONTEXT_COLLECTION)
            ->map(fn (Media $media) => (string) $media->name)
            ->all();

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $ordinal = 2;

        while (in_array("{$base}-{$ordinal}", $taken, true)) {
            $ordinal++;
        }

        return "{$base}-{$ordinal}";
    }
}
