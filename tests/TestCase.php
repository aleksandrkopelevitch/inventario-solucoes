<?php

namespace Tests;

use App\Support\Digibee\ConnectorDocMap;
use App\Support\Digibee\ConnectorReference;
use App\Support\Digibee\TenantVocabulary;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The Digibee reference artifacts are read through STATIC memos — they are
     * files that never change during a request, so re-reading them per prompt
     * would be waste. A static survives the per-test application, though, so a
     * test that points `services.digibee.cards_path` at a fixture would
     * otherwise leave that fixture answering for every test after it, in
     * whatever order the suite happens to run. Cheap to drop, impossible to
     * debug once it bites.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ConnectorDocMap::flush();
        ConnectorReference::flush();
        TenantVocabulary::flush();
    }
}
