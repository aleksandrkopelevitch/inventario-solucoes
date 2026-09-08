<?php

namespace App\Actions\Flowspec;

use App\Enums\DigibeeTriggerAuth;
use App\Enums\DigibeeTriggerKind;
use App\Support\Digibee\TriggerSpec;

/**
 * Builds the `triggerSpec` the generator does not produce, from the shape the
 * tenant's own pipelines actually store.
 *
 * The generated document is 2 keys of a 34-key pipeline, and this is the
 * missing one the rest of the lifecycle depends on: without it the runner
 * knows neither the URL nor the authentication mode of the endpoint it just
 * deployed, and 114 of the 201 exported pipelines are web-protocol triggers.
 *
 * **Every default here was measured, and the two interesting ones are where
 * the measurement was NOT followed.**
 *
 * - `requestContentTypes`/`responseContentTypes` default to JSON, though the
 *   corpus says XML by a mile (`text/xml, application/xml` in 51 of the 65
 *   `http` specs). That majority is a fact about a legacy SOAP estate, not a
 *   default for something being written now — the tenant vocabulary is
 *   authoritative about what a key is CALLED, never about what a new pipeline
 *   should want.
 * - `timeout` defaults to 30000, the platform's own default (and the most
 *   common single value), rather than to the 90s and 900s the corpus is full
 *   of. A generous timeout is a decision about a specific downstream system.
 *
 * And two things it refuses to invent, reported in `missing` instead: a
 * scheduler's cron expression and an event's name. Both are unguessable from
 * a flowSpec, and both fail by RUNNING rather than by erroring.
 */
class SynthesizeTriggerSpec
{
    /**
     * @param  array<string, mixed>  $options  methods, auth, uris, timeout, cron,
     *                                         timeZoneId, eventName, requestContentTypes,
     *                                         responseContentTypes, requestSizeLimit
     */
    public function handle(DigibeeTriggerKind $kind, array $options = []): TriggerSpec
    {
        $assumptions = [];
        $missing = [];

        $auth = $options['auth'] ?? null;
        $auth = $auth instanceof DigibeeTriggerAuth ? $auth : null;

        if ($auth === null && $kind->isWebProtocol()) {
            $auth = DigibeeTriggerAuth::defaultFor($kind);
            $assumptions[] = "Autenticação assumida: {$auth->label()} — é o que os pipelines "
                . "do tenant usam em triggers {$kind->label()}.";
        }

        $auth ??= DigibeeTriggerAuth::None;

        if ($auth === DigibeeTriggerAuth::None && $kind->isWebProtocol()) {
            $assumptions[] = 'Endpoint SEM autenticação — pedido explicitamente, '
                . 'nunca o default desta síntese.';
        }

        $spec = match ($kind) {
            DigibeeTriggerKind::Rest      => $this->webProtocol($kind, $auth, $options, $assumptions),
            DigibeeTriggerKind::Http      => $this->http($kind, $auth, $options, $assumptions),
            DigibeeTriggerKind::HttpFile  => $this->httpFile($kind, $auth, $options, $assumptions),
            DigibeeTriggerKind::Scheduler => $this->scheduler($options, $assumptions, $missing),
            DigibeeTriggerKind::Event     => $this->event($options, $assumptions, $missing),
        };

        ksort($spec);

        return new TriggerSpec(
            kind: $kind,
            auth: $auth,
            spec: $spec,
            assumptions: $assumptions,
            missing: $missing,
        );
    }

    /**
     * The keys every web-protocol trigger in the corpus carries, with the
     * values it carries them at: `external: true` / `internal: false` (65/65
     * and 46/46), `mtls`, `addCors`, `advanced` and `enableRateLimit` false
     * throughout, `requestSizeLimit: 5` (105 of the 106 that have it).
     *
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @return array<string, mixed>
     */
    private function webProtocol(
        DigibeeTriggerKind $kind,
        DigibeeTriggerAuth $auth,
        array $options,
        array &$assumptions,
    ): array {
        $methods = $this->methods($options, $assumptions);

        $spec = [
            'type'             => $kind->value,
            'name'             => $kind->specName(),
            'methods'          => $methods,
            'external'         => true,
            'internal'         => false,
            'mtls'             => false,
            'addCors'          => false,
            'corsHeaders'      => '',
            'headers'          => '',
            'advanced'         => false,
            'enableRateLimit'  => false,
            'allowRedelivery'  => false,
            'requestSizeLimit' => (int) ($options['requestSizeLimit'] ?? 5),
            'timeout'          => $this->timeout($options, $assumptions),
            ...$auth->flags(),
        ];

        // A REST trigger with no `uris` answers at the platform's default path
        // for the pipeline — which is what 32 of the 46 stored specs do. An
        // invented path is worse than no path: it publishes an endpoint at an
        // address nobody agreed on, and the runner then calls the default one
        // and 404s.
        $uris = $options['uris'] ?? null;

        if (is_array($uris) && $uris !== []) {
            $spec['uris'] = array_values(array_filter($uris, is_string(...)));
            $spec['advanced'] = true;
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @return array<string, mixed>
     */
    private function http(
        DigibeeTriggerKind $kind,
        DigibeeTriggerAuth $auth,
        array $options,
        array &$assumptions,
    ): array {
        $spec = $this->webProtocol($kind, $auth, $options, $assumptions);

        // `uris` is not a key of the `http` trigger in any of the 66 stored
        // specs — that one is REST's. Carrying it over from the shared builder
        // would write a key the canvas does not read.
        unset($spec['uris']);

        $spec['requestContentTypes'] = $this->contentTypes($options, 'requestContentTypes', $assumptions);
        $spec['responseContentTypes'] = $this->contentTypes($options, 'responseContentTypes', $assumptions);
        $spec['routes'] = [];

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @return array<string, mixed>
     */
    private function httpFile(
        DigibeeTriggerKind $kind,
        DigibeeTriggerAuth $auth,
        array $options,
        array &$assumptions,
    ): array {
        $spec = $this->webProtocol($kind, $auth, $options, $assumptions);
        unset($spec['uris']);

        $spec['responseContentTypes'] = $this->contentTypes($options, 'responseContentTypes', $assumptions);
        // The two upload modes are exclusive in all three stored specs, and
        // body upload is the one that carries a content-type list.
        $spec['bodyUpload'] = true;
        $spec['formDataUpload'] = false;
        $spec['bodyUploadContentTypes'] = $this->contentTypes($options, 'requestContentTypes', $assumptions);
        // 100 rather than the shared 5: a trigger whose purpose is a file
        // upload with a 5 MB ceiling is a trigger that fails on its first real
        // payload (2 of the 3 stored specs say 100).
        $spec['requestSizeLimit'] = (int) ($options['requestSizeLimit'] ?? 100);

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private function scheduler(array $options, array &$assumptions, array &$missing): array
    {
        $spec = [
            'type'                 => DigibeeTriggerKind::Scheduler->value,
            'name'                 => DigibeeTriggerKind::Scheduler->specName(),
            'allowRedelivery'      => false,
            'concurrentScheduling' => false,
            'retries'              => (int) ($options['retries'] ?? 0),
            'timeout'              => $this->timeout($options, $assumptions),
        ];

        $cron = $options['cron'] ?? null;

        if (is_string($cron) && trim($cron) !== '') {
            $spec['cronExpression'] = trim($cron);
        } else {
            $missing[] = 'cronExpression: um agendamento não sai do flowSpec. '
                . 'Um cron chutado não falha — ele roda, na hora errada.';
        }

        // Universal in the corpus: all 26 specs that carry a timezone carry
        // this one, and a scheduler without it runs in the cluster's UTC.
        $spec['timeZoneId'] = is_string($options['timeZoneId'] ?? null)
            ? $options['timeZoneId']
            : 'America/Sao_Paulo';

        if (! is_string($options['timeZoneId'] ?? null)) {
            $assumptions[] = 'Timezone assumido: America/Sao_Paulo (os 26 schedulers do tenant que declaram timezone usam esse).';
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @param  list<string>  $missing
     * @return array<string, mixed>
     */
    private function event(array $options, array &$assumptions, array &$missing): array
    {
        $spec = [
            'type'            => DigibeeTriggerKind::Event->value,
            'name'            => DigibeeTriggerKind::Event->specName(),
            'allowRedelivery' => false,
            'expiration'      => (int) ($options['expiration'] ?? 600000),
            'timeout'         => $this->timeout($options, $assumptions),
        ];

        $eventName = $options['eventName'] ?? null;

        if (is_string($eventName) && trim($eventName) !== '') {
            $spec['eventName'] = trim($eventName);
        } else {
            // An invented event name subscribes to a topic nothing publishes:
            // the pipeline deploys, reports healthy, and never runs.
            $missing[] = 'eventName: o nome do evento é um acordo com quem publica, '
                . 'e um nome inventado escuta um evento que não existe — sem erro nenhum.';
        }

        if (! isset($options['expiration'])) {
            $assumptions[] = 'Expiração assumida: 600000 ms (o valor mais comum nos 31 triggers de evento do tenant).';
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @return list<string>
     */
    private function methods(array $options, array &$assumptions): array
    {
        $methods = $options['methods'] ?? null;

        if (is_array($methods) && $methods !== []) {
            return array_values(array_map(
                fn (string $method) => strtoupper($method),
                array_filter($methods, is_string(...)),
            ));
        }

        $assumptions[] = 'Métodos assumidos: POST (o mais comum no tenant — 63 dos 114 triggers web).';

        return ['POST'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     * @return list<string>
     */
    private function contentTypes(array $options, string $key, array &$assumptions): array
    {
        $types = $options[$key] ?? null;

        if (is_array($types) && $types !== []) {
            return array_values(array_filter($types, is_string(...)));
        }

        $assumptions[] = "{$key} assumido: application/json. O corpus diz XML na maioria, "
            . 'mas isso descreve o legado SOAP e não o que um pipeline novo deveria falar.';

        return ['application/json'];
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $assumptions
     */
    private function timeout(array $options, array &$assumptions): int
    {
        if (isset($options['timeout']) && is_numeric($options['timeout'])) {
            return (int) $options['timeout'];
        }

        $assumptions[] = 'Timeout assumido: 30000 ms (o default da plataforma). '
            . 'Um timeout maior é uma decisão sobre um sistema específico.';

        return 30000;
    }
}
