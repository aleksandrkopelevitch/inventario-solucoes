<?php

namespace Database\Seeders;

use App\Enums\CompanyKind;
use App\Enums\PersonSolutionRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Person;
use App\Models\Solution;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importa as 81 soluções de database/data/inventory_seed.json (secao 14.1).
 * Idempotente: upsert por chaves naturais (slug), rodar duas vezes nao duplica.
 */
class SolutionSeeder extends Seeder
{
    private Company $leo;

    public function run(): void
    {
        $this->leo = Company::updateOrCreate(
            ['slug' => 'leo-madeiras'],
            ['name' => 'Leo Madeiras', 'kind' => CompanyKind::Internal],
        );

        $path = database_path('data/inventory_seed.json');
        $records = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($records as $record) {
            $vendor = $this->upsertVendor($record['vendor'] ?? '');
            $solution = $this->upsertSolution($record, $vendor);
            $this->attachOwners($record, $solution);
            if ($vendor) {
                $this->attachVendorContacts($record, $solution, $vendor);
            }
        }
    }

    /** Nulo quando o inventário não informa fornecedor — nunca cria uma Company vazia. */
    private function upsertVendor(string $vendor): ?Company
    {
        if (trim($vendor) === '') {
            return null;
        }

        $principal = $this->companyPrincipal($vendor);
        $isInternal = (bool) preg_match('/interno|leo madeiras/i', $vendor);

        if ($isInternal) {
            return $this->leo;
        }

        return Company::updateOrCreate(
            ['slug' => Str::slug($principal)],
            [
                'name'  => $principal,
                'kind'  => CompanyKind::Vendor,
                'notes' => $vendor !== $principal ? $vendor : null,
            ],
        );
    }

    private function upsertSolution(array $record, ?Company $vendor): Solution
    {
        return Solution::updateOrCreate(
            ['slug' => $record['slug']],
            [
                'name'                   => $record['name'],
                'description'            => $record['description'] ?? null,
                'vendor_company_id'      => $vendor?->id,
                'category'               => $record['category'],
                'directorate'            => $record['directorate'] ?? null,
                'support_type'           => $vendor?->kind === CompanyKind::Internal ? 'internal' : 'third_party',
                'environment'            => $record['environment'] ?? null,
                'cloud'                  => $record['cloud'] ?? null,
                'contract_status'        => $record['contract_status'] ?? 'unknown',
                'support_operation_note' => $record['support_operation_note'] ?? null,
                'status'                 => $record['status'] ?? 'active',
            ],
        );
    }

    private function attachOwners(array $record, Solution $solution): void
    {
        foreach ($this->splitNames($record['owner_tech'] ?? null) as $name) {
            $this->linkPerson($this->upsertInternalPerson($name), $solution, PersonSolutionRole::Technical, true);
        }

        foreach ($this->splitNames($record['owner_business'] ?? null) as $name) {
            $this->linkPerson($this->upsertInternalPerson($name), $solution, PersonSolutionRole::Business, true);
        }
    }

    private function attachVendorContacts(array $record, Solution $solution, Company $vendor): void
    {
        $contacts = $record['vendor_contacts'] ?? [];
        if (empty($contacts)) {
            return;
        }

        $personName = $this->mineVendorPersonName($contacts, $vendor->name)
            ?? 'Contato ' . $vendor->name;

        $person = Person::updateOrCreate(
            ['slug' => Str::slug($personName . ' ' . $vendor->slug)],
            ['name' => $personName, 'company_id' => $vendor->id],
        );

        foreach ($contacts as $item) {
            $type = in_array($item['type'] ?? '', ['email', 'phone', 'whatsapp', 'other'], true)
                ? $item['type']
                : 'other';

            // Dedup de contatos idênticos (mesma pessoa, tipo e valor).
            Contact::firstOrCreate(
                ['person_id' => $person->id, 'type' => $type, 'value' => $item['value'] ?? ''],
                ['is_primary' => false],
            );
        }

        $this->linkPerson($person, $solution, PersonSolutionRole::VendorContact, false);
    }

    private function upsertInternalPerson(string $name): Person
    {
        return Person::updateOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'company_id' => $this->leo->id],
        );
    }

    private function linkPerson(Person $person, Solution $solution, PersonSolutionRole $role, bool $isPrimary): void
    {
        DB::table('person_solution')->updateOrInsert(
            ['person_id' => $person->id, 'solution_id' => $solution->id, 'role' => $role->value],
            ['is_primary' => $isPrimary, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** Primeiro token antes de "/" ou "(" (secao 6.2 / 14.1). */
    private function companyPrincipal(string $vendor): string
    {
        $principal = preg_split('/[\/(]/', $vendor)[0] ?? $vendor;

        return trim($principal) !== '' ? trim($principal) : trim($vendor);
    }

    /** @return array<int, string> */
    private function splitNames(?string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        return collect(preg_split('/[\/;]/', $raw))
            ->map(fn (string $n) => trim($n))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Best-effort: minera um nome de pessoa do texto cru do item type=other. */
    private function mineVendorPersonName(array $contacts, string $principal): ?string
    {
        $blacklist = ['fone', 'comercial', 'suporte', 'tel', 'telefone', 'contato', 'whatsapp', 'email', 'e-mail', 'devpartner'];

        foreach ($contacts as $c) {
            if (($c['type'] ?? null) !== 'other') {
                continue;
            }

            $raw = (string) ($c['value'] ?? '');
            $raw = preg_replace('/\S+@\S+/', ' ', $raw);          // remove e-mails
            $raw = preg_replace('/[+(]?\d[\d\s\-()]{5,}/', ' ', $raw); // remove telefones

            foreach (preg_split('/[•|,;.\-–:]+/u', $raw) as $part) {
                $part = trim($part);
                if ($part === '' || mb_strlen($part) < 3) {
                    continue;
                }
                if (! preg_match('/^\p{Lu}[\p{L}]+(?:\s+\p{L}[\p{L}]*){0,3}$/u', $part)) {
                    continue;
                }
                $lower = Str::lower($part);
                if (in_array($lower, $blacklist, true) || $lower === Str::lower($principal)) {
                    continue;
                }

                return $part;
            }
        }

        return null;
    }
}
