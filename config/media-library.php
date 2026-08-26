<?php

/**
 * ONE key, on purpose.
 *
 * Spatie's provider registers its own config with `mergeConfigFrom()`, which
 * merges the package defaults with whatever this file declares — so this stays a
 * single override instead of a published 200-line copy that would silently
 * freeze every other default at today's values.
 *
 * `max_file_size` is a LIBRARY-level guard: exceed it and `addMedia*()` throws
 * `FileIsTooBig`, wherever the file came from. It is not the guard that protects
 * the app from someone uploading something absurd — that job belongs to the
 * FormRequests, and every path that accepts a file has its own explicit rule
 * (documentation media, Solution context documents, CATI sources and flowSpec
 * attachments at `max:20480`; chain images at 10240; C4/diagram uploads at
 * 8192). Raising this ceiling therefore loosens nothing a person can reach
 * through the UI.
 *
 * What it does unblock is `gitbook:import`, whose own ceiling
 * (`services.gitbook.max_asset_bytes`) was being clamped by Spatie's 10MB
 * default: the first full import left 12 assets behind for size alone, the
 * largest a 59.5MB attachment. 64MB clears the whole known corpus with room to
 * spare, and the import still refuses anything past its own ceiling — see
 * `GitbookAssetImporter::ceiling()`, which takes the SMALLER of the two so a
 * mistake here can't make the importer greedier than its own setting.
 */
return [
    'max_file_size' => 1024 * 1024 * 64,
];
