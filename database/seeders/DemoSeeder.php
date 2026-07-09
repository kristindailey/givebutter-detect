<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Contact;
use App\Models\Email;
use App\Models\ExternalId;
use App\Models\Household;
use App\Models\Phone;
use App\Models\Tag;
use App\Models\Transaction;
use App\Services\Detection\Normalizer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The curated dataset the live demo runs against: two hero cases, seven
 * review-band pairs for queue depth, and ~2k Faker noise contacts that must
 * produce no candidates of their own.
 *
 * Deterministic. The Faker seed is fixed and the curated contacts carry explicit
 * IDs, so the Detection and MergeService tests can assert against known rows and
 * `seed:demo` reproduces the exact same database every time.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public const int FAKER_SEED = 2024;

    /** Hero case 1 — the catch. Merged in the demo. */
    public const int JENNIFER_ID = 1001;

    public const int JEN_ID = 1002;

    /** Hero case 2 — the non-merge. Must never reach the queue. */
    public const int PARENT_ID = 1003;

    public const int CHILD_ID = 1004;

    /** The last hand-built contact. Everything above this ID is Faker noise. */
    public const int LAST_CURATED_ID = 1018;

    /** Noise starts here, leaving room to add curated contacts without renumbering. */
    private const int NOISE_ID_FLOOR = 2001;

    private const int NOISE_CONTACTS = 2000;

    /**
     * `state` never feeds a blocking key, so any plausible value does. Listed here
     * rather than taken from Faker's `stateAbbr()`, which lives on a locale
     * provider and isn't visible to static analysis.
     *
     * @var list<string>
     */
    private const array STATE_ABBREVIATIONS = [
        'AZ', 'CA', 'CO', 'FL', 'GA', 'IL', 'MA', 'MI', 'MN', 'NC',
        'NJ', 'NM', 'NY', 'OH', 'OR', 'PA', 'TX', 'VA', 'WA', 'WI',
    ];

    /**
     * Every normalized value already spoken for. Noise is checked against this so
     * a random Faker contact can never collide with a curated one — a stray
     * duplicate in the noise would surface in the queue as an unexplained pair.
     *
     * @var array<string, array<string, true>>
     */
    private array $taken = [
        'name_key' => [],
        'address_key' => [],
        'email' => [],
        'phone' => [],
    ];

    public function __construct(private readonly Normalizer $normalizer) {}

    public function run(): void
    {
        fake()->seed(self::FAKER_SEED);

        DB::transaction(function () {
            $this->seedHeroCaseOne();
            $this->seedHeroCaseTwo();
            $this->seedReviewBandPairs();
            $this->seedNoise();
        });
    }

    /**
     * Jennifer / Jen — different emails, different phones, shared household, same
     * `dob`. The naive exact-match rule keeps them apart; the prototype matches
     * them at ≈94 off fuzzy preferred-name + household + `dob`.
     *
     * The transaction figures are locked, because the before/after panel is the
     * demo's payoff:
     *
     *   total_contributions  $1,200.00 → $1,700.00   (Jen's refund excluded)
     *   contact_since        2021-06-02 → 2019-03-14 (the moment)
     *   last_donation_amount $50.00 → $50.00         (unchanged; Jennifer's latest)
     */
    private function seedHeroCaseOne(): void
    {
        $jennifer = $this->createContact(self::JENNIFER_ID, [
            'first_name' => 'Jennifer',
            'last_name' => 'Smith',
            'dob' => '1985-04-12',
            'title' => 'Director of Operations',
            'company' => 'Acme Corp',
        ],
            emails: [['type' => 'work', 'value' => 'jennifer.smith@acmecorp.com']],
            phones: [['type' => 'work', 'value' => '(415) 555-0182']],
            address: ['address_1' => '18 Alder Street', 'city' => 'Oakland', 'state' => 'CA', 'zipcode' => '94607'],
        );

        // Earliest gift 2021-06-02; latest is the $50 that survives the merge as
        // `last_donation_amount`. Sums to exactly $1,200.
        $this->createTransaction($jennifer, 'txn_hero1_jennifer_01', '1000.00', '2021-06-02 14:22:00');
        $this->createTransaction($jennifer, 'txn_hero1_jennifer_02', '150.00', '2022-09-10 09:05:00');
        $this->createTransaction($jennifer, 'txn_hero1_jennifer_03', '50.00', '2023-11-20 17:41:00');

        $jen = $this->createContact(self::JEN_ID, [
            'first_name' => 'Jen',
            'preferred_name' => 'Jen',
            'last_name' => 'Smith',
            'dob' => '1985-04-12',
        ],
            emails: [['type' => 'personal', 'value' => 'jensmith88@gmail.com']],
            phones: [['type' => 'mobile', 'value' => '(510) 555-3391']],
            address: ['address_1' => '18 Alder Street', 'city' => 'Oakland', 'state' => 'CA', 'zipcode' => '94607'],
        );

        // The gift that drags `contact_since` backward on merge...
        $this->createTransaction($jen, 'txn_hero1_jen_01', '500.00', '2019-03-14 11:30:00');

        // ...and a refunded one, so the recompute's refund-exclusion is proven
        // rather than assumed. $250 that must never reach the $1,700 total.
        $this->createTransaction(
            $jen,
            'txn_hero1_jen_02_refunded',
            '250.00',
            '2020-01-15 08:12:00',
            refundedAt: '2020-02-02 10:00:00',
        );

        // `board-prospect` is on both: four tags union to three, so the merge
        // preview's read-only "kept both" summary proves it dedupes.
        $this->attachTags($jennifer, ['major-donor', 'board-prospect']);
        $this->attachTags($jen, ['board-prospect', 'email-subscriber']);

        $this->attachExternalIds($jennifer, [['label' => 'bloomerang', 'external_id' => 'BLM-1001']]);
        $this->attachExternalIds($jen, [['label' => 'mailchimp', 'external_id' => 'MC-8842']]);

        // The block that actually generates this pair. Jennifer/Jen share no email
        // or phone, and trigram('jen smith','jennifer smith') sits near the
        // threshold — without this household, the headline pair never gets scored.
        $this->createHousehold('Smith Household', $jennifer, [$jennifer, $jen], 'Jennifer Smith');

        $this->recomputeDerivedFields($jennifer);
        $this->recomputeDerivedFields($jen);
    }

    /**
     * Parent / child — a shared household inbox, shared surname, shared address,
     * and conflicting `dob`. The naive rule merges them on the email alone. The
     * prototype scores ≈35: the household modifier dampens the shared email, and
     * the `dob` conflict pushes toward "different people". Never enters the queue.
     */
    private function seedHeroCaseTwo(): void
    {
        $householdEmail = ['type' => 'home', 'value' => 'hayesfamily@gmail.com'];
        $householdAddress = ['address_1' => '204 Cedar Lane', 'city' => 'Berkeley', 'state' => 'CA', 'zipcode' => '94702'];

        $parent = $this->createContact(self::PARENT_ID, [
            'first_name' => 'Robert',
            'last_name' => 'Hayes',
            'dob' => '1968-02-20',
            'title' => 'Architect',
        ],
            emails: [$householdEmail],
            phones: [['type' => 'mobile', 'value' => '(510) 555-7712']],
            address: $householdAddress,
        );

        $child = $this->createContact(self::CHILD_ID, [
            'first_name' => 'Tyler',
            'last_name' => 'Hayes',
            // Thirty-one years apart. The zero-cost disambiguator.
            'dob' => '1999-07-30',
        ],
            emails: [$householdEmail],
            phones: [['type' => 'mobile', 'value' => '(510) 555-4408']],
            address: $householdAddress,
        );

        $this->createTransaction($parent, 'txn_hero2_parent_01', '750.00', '2020-05-18 13:00:00');
        $this->createTransaction($child, 'txn_hero2_child_01', '25.00', '2023-04-02 19:24:00');

        $this->createHousehold('Hayes Household', $parent, [$parent, $child], 'The Hayes Family');

        $this->recomputeDerivedFields($parent);
        $this->recomputeDerivedFields($child);
    }

    /**
     * Seven hand-built pairs that fire different signal combinations, so the
     * queue's per-signal "why" breakdown has varied inputs to render.
     *
     * Seeded by *signal combination*, not by target score — no scorer exists until
     * Detection phase 2, and its weights are hand-tuned against the hero cases.
     * Expect one tuning pass there to land these across 61–88.
     */
    private function seedReviewBandPairs(): void
    {
        // 1. Exact email + a one-letter name typo.
        $this->createPair(1005, 1006,
            ['first_name' => 'Michael', 'last_name' => 'Chen', 'dob' => '1979-11-03'],
            ['first_name' => 'Micheal', 'last_name' => 'Chen'],
            emailsA: [['type' => 'personal', 'value' => 'm.chen@fastmail.com']],
            emailsB: [['type' => 'personal', 'value' => 'm.chen@fastmail.com']],
            phonesA: [['type' => 'mobile', 'value' => '(206) 555-1120']],
            phonesB: [['type' => 'mobile', 'value' => '(206) 555-8834']],
            addressA: ['address_1' => '77 Pike Street', 'city' => 'Seattle', 'state' => 'WA', 'zipcode' => '98101'],
            addressB: ['address_1' => '412 Union Street', 'city' => 'Seattle', 'state' => 'WA', 'zipcode' => '98101'],
        );

        // 2. Shared phone (one formatted with a country code) + a nickname.
        $this->createPair(1007, 1008,
            ['first_name' => 'Katherine', 'last_name' => 'Brooks', 'dob' => '1990-06-25'],
            ['first_name' => 'Kate', 'preferred_name' => 'Kate', 'last_name' => 'Brooks'],
            emailsA: [['type' => 'work', 'value' => 'kbrooks@northwind.org']],
            emailsB: [['type' => 'personal', 'value' => 'kate.brooks@hey.com']],
            phonesA: [['type' => 'mobile', 'value' => '(312) 555-6690']],
            phonesB: [['type' => 'mobile', 'value' => '+1 312 555 6690']],
            addressA: ['address_1' => '900 Lakeview Drive', 'city' => 'Chicago', 'state' => 'IL', 'zipcode' => '60613'],
            addressB: ['address_1' => '221 Wabash Avenue', 'city' => 'Chicago', 'state' => 'IL', 'zipcode' => '60604'],
        );

        // 3. Same name + a trigram-near address ("Ter" vs "Terrace"). No contact overlap.
        $this->createPair(1009, 1010,
            ['first_name' => 'David', 'last_name' => 'Okafor', 'dob' => '1983-01-19'],
            ['first_name' => 'David', 'last_name' => 'Okafor'],
            emailsA: [['type' => 'personal', 'value' => 'dokafor@zoho.com']],
            emailsB: [['type' => 'work', 'value' => 'd.okafor@brightpath.io']],
            phonesA: [['type' => 'home', 'value' => '(713) 555-2201']],
            phonesB: [['type' => 'mobile', 'value' => '(713) 555-9987']],
            addressA: ['address_1' => '742 Evergreen Ter', 'city' => 'Houston', 'state' => 'TX', 'zipcode' => '77002'],
            addressB: ['address_1' => '742 Evergreen Terrace', 'city' => 'Houston', 'state' => 'TX', 'zipcode' => '77002'],
        );

        // 4. Exact email, initial-only first name — the name signal barely fires.
        $this->createPair(1011, 1012,
            ['first_name' => 'Linda', 'last_name' => 'Park', 'dob' => '1974-09-08'],
            ['first_name' => 'L', 'last_name' => 'Park'],
            emailsA: [['type' => 'personal', 'value' => 'lindapark@outlook.com']],
            emailsB: [['type' => 'personal', 'value' => 'lindapark@outlook.com']],
            phonesA: [['type' => 'mobile', 'value' => '(602) 555-3345']],
            phonesB: [],
            addressA: ['address_1' => '55 Camelback Road', 'city' => 'Phoenix', 'state' => 'AZ', 'zipcode' => '85012'],
            addressB: ['address_1' => '55 Camelback Rd', 'city' => 'Phoenix', 'state' => 'AZ', 'zipcode' => '85012'],
        );

        // 5. Identical name + near-identical address, no shared email or phone.
        $this->createPair(1013, 1014,
            ['first_name' => 'Maria', 'last_name' => 'Gonzalez', 'dob' => '1988-03-30'],
            ['first_name' => 'Maria', 'last_name' => 'Gonzalez'],
            emailsA: [['type' => 'personal', 'value' => 'mgonzalez@proton.me']],
            emailsB: [['type' => 'work', 'value' => 'maria.g@riverstone.org']],
            phonesA: [['type' => 'mobile', 'value' => '(505) 555-4412']],
            phonesB: [['type' => 'work', 'value' => '(505) 555-7781']],
            addressA: ['address_1' => '310 Sandia Court', 'city' => 'Albuquerque', 'state' => 'NM', 'zipcode' => '87109'],
            addressB: ['address_1' => '310 Sandia Ct.', 'city' => 'Albuquerque', 'state' => 'NM', 'zipcode' => '87109'],
        );

        // 6. Shared phone + shared address + a near-name (Ana/Anna).
        $this->createPair(1015, 1016,
            ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'dob' => '1995-12-11'],
            ['first_name' => 'Anna', 'last_name' => 'Ruiz'],
            emailsA: [['type' => 'personal', 'value' => 'ana.ruiz@gmail.com']],
            emailsB: [['type' => 'work', 'value' => 'aruiz@claritydesign.com']],
            phonesA: [['type' => 'mobile', 'value' => '(305) 555-8123']],
            phonesB: [['type' => 'mobile', 'value' => '305-555-8123']],
            addressA: ['address_1' => '1200 Brickell Avenue', 'city' => 'Miami', 'state' => 'FL', 'zipcode' => '33131'],
            addressB: ['address_1' => '1200 Brickell Ave', 'city' => 'Miami', 'state' => 'FL', 'zipcode' => '33131'],
        );

        // 7. Email + phone both match, name is a diminutive. The strongest of the set.
        $this->createPair(1017, 1018,
            ['first_name' => 'Samuel', 'last_name' => 'Iyer', 'dob' => '1981-07-16'],
            ['first_name' => 'Sam', 'preferred_name' => 'Sam', 'last_name' => 'Iyer'],
            emailsA: [['type' => 'work', 'value' => 'samuel.iyer@meridian.co']],
            emailsB: [['type' => 'work', 'value' => 'samuel.iyer@meridian.co']],
            phonesA: [['type' => 'work', 'value' => '(617) 555-0044']],
            phonesB: [['type' => 'work', 'value' => '617.555.0044']],
            addressA: ['address_1' => '9 Beacon Street', 'city' => 'Boston', 'state' => 'MA', 'zipcode' => '02108'],
            addressB: ['address_1' => '9 Beacon Street', 'city' => 'Boston', 'state' => 'MA', 'zipcode' => '02108'],
        );
    }

    /**
     * ~2,000 Faker contacts. Every normalized key is checked against everything
     * already seeded, so noise can neither duplicate itself nor collide with a
     * curated contact — the queue must contain only the pairs we put there.
     *
     * Bulk-inserted rather than saved one model at a time: ~2k contacts with their
     * children is roughly 8k rows, and per-row Eloquent would dominate the
     * seeder's runtime for no benefit.
     */
    private function seedNoise(): void
    {
        $contacts = [];
        $emails = [];
        $phones = [];
        $addresses = [];
        $transactions = [];
        $now = now();
        $nextId = self::NOISE_ID_FLOOR;

        for ($i = 0; $i < self::NOISE_CONTACTS; $i++) {
            $id = $nextId++;

            [$firstName, $lastName, $nameKey] = $this->uniqueName();
            $email = $this->uniqueEmail();
            $phone = $this->uniquePhone();
            [$address, $addressKey] = $this->uniqueAddress();

            $contactTransactions = $this->buildNoiseTransactions($id);
            $derived = $this->deriveFrom($contactTransactions);

            $contacts[] = [
                'id' => $id,
                'type' => 'individual',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'dob' => fake()->dateTimeBetween('-75 years', '-19 years')->format('Y-m-d'),
                'primary_email' => $email,
                'primary_phone' => $phone,
                'name_key' => $nameKey,
                'address_key' => $addressKey,
                ...$derived,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $emails[] = [
                'contact_id' => $id, 'type' => 'personal', 'value' => $email,
                'normalized_value' => $this->normalizer->email($email),
                'created_at' => $now, 'updated_at' => $now,
            ];
            $phones[] = [
                'contact_id' => $id, 'type' => 'mobile', 'value' => $phone,
                'normalized_value' => $this->normalizer->phone($phone),
                'created_at' => $now, 'updated_at' => $now,
            ];
            $addresses[] = [
                'contact_id' => $id, ...$address, 'country' => 'US', 'type' => 'home',
                'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
            ];

            foreach ($contactTransactions as $transaction) {
                $transactions[] = [...$transaction, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        $this->insertChunked('contacts', $contacts);
        $this->insertChunked('emails', $emails);
        $this->insertChunked('phones', $phones);
        $this->insertChunked('addresses', $addresses);
        $this->insertChunked('transactions', $transactions);

        $this->syncContactIdSequence();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function uniqueName(): array
    {
        do {
            $firstName = fake()->firstName();
            $lastName = fake()->lastName();
            $nameKey = $this->normalizer->nameKey($firstName, $lastName);
        } while ($nameKey === null || isset($this->taken['name_key'][$nameKey]));

        $this->taken['name_key'][$nameKey] = true;

        return [$firstName, $lastName, $nameKey];
    }

    private function uniqueEmail(): string
    {
        do {
            $email = fake()->safeEmail();
            $normalized = $this->normalizer->email($email);
        } while ($normalized === null || isset($this->taken['email'][$normalized]));

        $this->taken['email'][$normalized] = true;

        return $email;
    }

    private function uniquePhone(): string
    {
        do {
            $phone = fake()->numerify('(###) ###-####');
            $normalized = $this->normalizer->phone($phone);
        } while ($normalized === null || isset($this->taken['phone'][$normalized]));

        $this->taken['phone'][$normalized] = true;

        return $phone;
    }

    /**
     * @return array{0: array{address_1: string, city: string, state: string, zipcode: string}, 1: string}
     */
    private function uniqueAddress(): array
    {
        do {
            $address = [
                'address_1' => fake()->streetAddress(),
                'city' => fake()->city(),
                'state' => fake()->randomElement(self::STATE_ABBREVIATIONS),
                'zipcode' => fake()->postcode(),
            ];
            $addressKey = $this->normalizer->addressKey($address['address_1'], $address['city'], $address['zipcode']);
        } while ($addressKey === null || isset($this->taken['address_key'][$addressKey]));

        $this->taken['address_key'][$addressKey] = true;

        return [$address, $addressKey];
    }

    /**
     * A quarter of the file has never given, which keeps `contact_since` nullable
     * in the queue's contact previews rather than universally set.
     *
     * @return list<array<string, mixed>>
     */
    private function buildNoiseTransactions(int $contactId): array
    {
        $transactions = [];
        $count = fake()->numberBetween(0, 3);

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            $transactions[] = [
                'id' => sprintf('txn_noise_%d_%02d', $contactId, $sequence),
                'contact_id' => $contactId,
                'amount' => (string) fake()->randomFloat(2, 5, 2500),
                'status' => Transaction::STATUS_SUCCEEDED,
                'payment_method' => fake()->randomElement(['card', 'ach', 'paypal']),
                'captured_at' => fake()->dateTimeBetween('-6 years', 'now')->format('Y-m-d H:i:s'),
                'refunded_at' => null,
            ];
        }

        return $transactions;
    }

    /**
     * Creates a curated contact with its child rows and both blocking keys.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{type: string, value: string}>  $emails
     * @param  list<array{type: string, value: string}>  $phones
     * @param  array<string, string>|null  $address
     */
    private function createContact(int $id, array $attributes, array $emails = [], array $phones = [], ?array $address = null): Contact
    {
        $contact = new Contact($attributes);
        $contact->id = $id;
        $contact->primary_email = $emails[0]['value'] ?? null;
        $contact->primary_phone = $phones[0]['value'] ?? null;
        $contact->name_key = $this->normalizer->nameKey($attributes['first_name'] ?? null, $attributes['last_name'] ?? null);
        $contact->save();

        foreach ($emails as $email) {
            $contact->emails()->save(new Email([
                ...$email,
                'normalized_value' => $this->normalizer->email($email['value']),
            ]));
            $this->claim('email', $this->normalizer->email($email['value']));
        }

        foreach ($phones as $phone) {
            $contact->phones()->save(new Phone([
                ...$phone,
                'normalized_value' => $this->normalizer->phone($phone['value']),
            ]));
            $this->claim('phone', $this->normalizer->phone($phone['value']));
        }

        if ($address !== null) {
            $contact->addresses()->save(new Address([...$address, 'country' => 'US', 'type' => 'home', 'is_primary' => true]));

            // `address_key` derives from the primary address, so it can only be
            // written once that address exists.
            $contact->address_key = $this->normalizer->addressKey($address['address_1'] ?? null, $address['city'] ?? null, $address['zipcode'] ?? null);
            $contact->save();
        }

        $this->claim('name_key', $contact->name_key);
        $this->claim('address_key', $contact->address_key);

        return $contact;
    }

    /**
     * A curated review-band pair: two contacts, each with a small giving history.
     *
     * @param  array<string, mixed>  $attributesA
     * @param  array<string, mixed>  $attributesB
     * @param  list<array{type: string, value: string}>  $emailsA
     * @param  list<array{type: string, value: string}>  $emailsB
     * @param  list<array{type: string, value: string}>  $phonesA
     * @param  list<array{type: string, value: string}>  $phonesB
     * @param  array<string, string>  $addressA
     * @param  array<string, string>  $addressB
     */
    private function createPair(
        int $idA,
        int $idB,
        array $attributesA,
        array $attributesB,
        array $emailsA,
        array $emailsB,
        array $phonesA,
        array $phonesB,
        array $addressA,
        array $addressB,
    ): void {
        $a = $this->createContact($idA, $attributesA, $emailsA, $phonesA, $addressA);
        $b = $this->createContact($idB, $attributesB, $emailsB, $phonesB, $addressB);

        foreach ([$a, $b] as $contact) {
            $count = fake()->numberBetween(1, 3);

            for ($sequence = 1; $sequence <= $count; $sequence++) {
                $this->createTransaction(
                    $contact,
                    sprintf('txn_pair_%d_%02d', $contact->id, $sequence),
                    (string) fake()->randomFloat(2, 25, 1500),
                    fake()->dateTimeBetween('-5 years', 'now')->format('Y-m-d H:i:s'),
                );
            }

            $this->recomputeDerivedFields($contact);
        }
    }

    /**
     * Tags are never matched on and never diffed — they exist only so a merge can
     * union them. Jennifer and Jen deliberately overlap on one tag, so the merge
     * preview's "kept both" summary has a dedupe to actually perform rather than a
     * concatenation to display.
     *
     * @param  list<string>  $names
     */
    private function attachTags(Contact $contact, array $names): void
    {
        foreach ($names as $name) {
            $tag = Tag::firstOrCreate(['name' => $name]);
            $contact->tags()->attach($tag->id);
        }
    }

    /**
     * Mirrored, not matched — external-ID matching is the weekend cut line. Seeded
     * so the union summary renders a real value instead of an empty row.
     *
     * @param  list<array{label: string, external_id: string}>  $externalIds
     */
    private function attachExternalIds(Contact $contact, array $externalIds): void
    {
        foreach ($externalIds as $externalId) {
            $contact->externalIds()->save(new ExternalId($externalId));
        }
    }

    /**
     * @param  list<Contact>  $members
     */
    private function createHousehold(string $name, Contact $head, array $members, string $envelopeName): Household
    {
        $household = Household::create([
            'name' => $name,
            'head_contact_id' => $head->id,
            'envelope_name' => $envelopeName,
        ]);

        $household->members()->attach(collect($members)->pluck('id')->all());

        return $household;
    }

    private function createTransaction(Contact $contact, string $id, string $amount, string $capturedAt, ?string $refundedAt = null): Transaction
    {
        return Transaction::create([
            'id' => $id,
            'contact_id' => $contact->id,
            'amount' => $amount,
            'status' => Transaction::STATUS_SUCCEEDED,
            'payment_method' => 'card',
            'captured_at' => $capturedAt,
            'refunded_at' => $refundedAt,
        ]);
    }

    /**
     * Writes the three derived fields to their **pre-merge** values, so the
     * before/after panel has a real "before" to correct.
     *
     * Applies the same rules MergeService recomputes with — sum, earliest, latest
     * over succeeded and unrefunded rows. Duplicated here on purpose: the seeder
     * must not depend on the service whose output the tests use it to verify.
     */
    private function recomputeDerivedFields(Contact $contact): void
    {
        $derived = $this->deriveFrom(array_values(
            $contact->transactions()->get()->map(fn (Transaction $transaction) => [
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'captured_at' => $transaction->captured_at?->toDateTimeString(),
                'refunded_at' => $transaction->refunded_at?->toDateTimeString(),
            ])->all(),
        ));

        // The derived fields are deliberately guarded out of `$fillable`.
        $contact->forceFill($derived)->save();
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return array{total_contributions: string, contact_since: ?string, last_donation_amount: ?string}
     */
    private function deriveFrom(array $transactions): array
    {
        $succeeded = collect($transactions)
            ->filter(fn (array $transaction) => $transaction['status'] === Transaction::STATUS_SUCCEEDED && $transaction['refunded_at'] === null)
            ->sortBy('captured_at')
            ->values();

        if ($succeeded->isEmpty()) {
            return ['total_contributions' => '0.00', 'contact_since' => null, 'last_donation_amount' => null];
        }

        return [
            'total_contributions' => $this->money($succeeded->sum(fn (array $transaction) => (float) $transaction['amount'])),
            'contact_since' => Carbon::parse((string) $succeeded->first()['captured_at'])->toDateString(),
            'last_donation_amount' => $this->money((float) $succeeded->last()['amount']),
        ];
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function claim(string $bucket, ?string $value): void
    {
        if ($value !== null) {
            $this->taken[$bucket][$value] = true;
        }
    }

    /**
     * Every contact here is inserted with an explicit ID, which on Postgres leaves
     * the identity sequence sitting at 1. Without this, the app's first runtime
     * insert would collide with a seeded row.
     */
    private function syncContactIdSequence(): void
    {
        DB::statement(
            "select setval(pg_get_serial_sequence('contacts', 'id'), ?, true)",
            [(int) DB::table('contacts')->max('id')],
        );
    }
}
