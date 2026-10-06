<?php

namespace App\Enums;

/**
 * Who is reading the solutions spreadsheet.
 *
 * The two audiences see the same screen, built by the same service — the only
 * thing this decides is what a column or a cell may carry: the magic link has
 * no identity behind it, so it never gets people's e-mails and phones, and its
 * solution names do not link into an inventory the reader cannot open.
 */
enum SpreadsheetAudience: string
{
    case Internal = 'internal';
    case Shared = 'shared';
}
