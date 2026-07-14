<?php

namespace App\Http\Controllers;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve a mídia embutida na documentação (coleção `docs`) pela URL estável
 * `/files/{id}` usada dentro do Markdown (<img src="/files/{id}">,
 * {% file src="/files/{id}" %}). Fica sob o grupo `auth`, então só usuários
 * autenticados acessam — a doc é privada como o resto do inventário.
 */
class MediaController extends Controller
{
    public function show(Media $media): BinaryFileResponse
    {
        abort_unless($media->collection_name === 'docs', 404);

        return response()->file($media->getPath(), [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($media->file_name) . '"',
        ]);
    }
}
