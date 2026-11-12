<?php

namespace App\Console\Commands;

use App\Models\AttributeOption;
use App\Models\Company;
use App\Models\Person;
use App\Models\Solution;
use App\Support\Images\PublicImage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Makes the thumbnail of every logo, photo and hosting picture that does not
 * have one yet (`PublicImage`). An upload makes its own; this fills the
 * backlog — the whole catalog on the first run, and nothing once every file
 * has one — so it runs on every deploy, like `documentation:web-images`.
 *
 * It only reads the originals and writes beside them on the public disk, so
 * on the droplet it runs as www-data (Envoy does), or php-fpm cannot replace
 * a thumbnail root wrote.
 */
class MakeImageThumbnailsCommand extends Command
{
    protected $signature = 'images:thumbnails';

    protected $description = 'Make the thumbnail of every logo, photo and hosting picture that does not have one yet';

    public function handle(): int
    {
        $made = 0;
        $present = 0;
        $missing = 0;

        $sources = [
            [Solution::class, 'logo_path'],
            [Company::class, 'logo_path'],
            [Person::class, 'photo_path'],
            [AttributeOption::class, 'image_path'],
        ];

        foreach ($sources as [$model, $column]) {
            $model::query()
                ->whereNotNull($column)
                ->where($column, '<>', '')
                ->select(['id', $column])
                ->lazyById()
                ->each(function (Model $row) use ($column, &$made, &$present, &$missing) {
                    $path = (string) $row->{$column};

                    if (PublicImage::hasThumbnail($path)) {
                        $present++;

                        return;
                    }

                    // A column naming a file that is not on disk (dev's
                    // half-copied storage, a logo deleted by hand) is counted,
                    // not failed on: the next row still gets its thumbnail.
                    PublicImage::makeThumbnail($path) ? $made++ : $missing++;
                });
        }

        $this->components->info("Made {$made} thumbnail(s); {$present} already there.");

        if ($missing > 0) {
            $this->components->warn("Skipped {$missing} whose original is missing or unreadable.");
        }

        return self::SUCCESS;
    }
}
