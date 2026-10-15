<?php

namespace App\Http\Requests;

use App\Actions\Documentation\RenameContextDocument;
use App\Models\Notebook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class UpdateContextDocumentRequest extends FormRequest
{
    /** Only whoever edits the caderno manages its context documents. */
    public function authorize(): bool
    {
        $notebook = $this->route('notebook');

        return $notebook !== null && $this->user()->can('update', $notebook);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * Two documents with the same name are two pills nobody can choose between
     * (the reason AttachContextText keeps pasted names unique), so a rename onto
     * a name the caderno already uses is refused.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Notebook $notebook */
                $notebook = $this->route('notebook');
                /** @var Media $media */
                $media = $this->route('media');

                $fileName = RenameContextDocument::fileNameFor($media, (string) $this->input('name'));

                if (pathinfo($fileName, PATHINFO_FILENAME) === '') {
                    $validator->errors()->add('name', 'Informe um nome para o documento.');

                    return;
                }

                $taken = $notebook->getMedia(Notebook::CONTEXT_COLLECTION)
                    ->reject(fn (Media $other) => $other->is($media))
                    ->contains(fn (Media $other) => strcasecmp($other->file_name, $fileName) === 0);

                if ($taken) {
                    $validator->errors()->add('name', 'Já existe um documento com esse nome neste caderno.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe um nome para o documento.',
            'name.max'      => 'O nome pode ter até 120 caracteres.',
        ];
    }
}
