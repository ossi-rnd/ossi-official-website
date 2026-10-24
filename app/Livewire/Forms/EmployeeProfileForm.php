<?php

namespace App\Livewire\Forms;

use App\Actions\People\ShiftForTimes;
use App\Enums\EmploymentStatus;
use App\Enums\Position;
use App\Enums\Region;
use App\Models\Client;
use App\Models\EmployeeProfile;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Support\EmployeeNumber;
use App\Support\GovernmentId;
use App\Support\PhilippineFormats;
use App\Support\Provinces;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * The HR employee record, used by both "Add employee" and "Edit". A Form
 * object keeps 20 fields and their rules out of the page component.
 */
class EmployeeProfileForm extends Form
{
    /** ABO and Rh: a list, so nobody types "o pos". */
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public ?int $profileId = null;

    /** The number as saved, so an untouched old-format number (EMP-1001) still passes. */
    public ?string $savedEmployeeNumber = null;

    /**
     * Where the number comes from: "generate" (a new record: from the hire date and
     * province of birth, EmployeeNumber::generate), "keep" (editing: it never changes
     * by itself, it's the ID card's identity) or "card" (the number on an OSSI ID
     * card already printed, typed by HR).
     */
    public string $numberSource = self::GENERATE;

    public const GENERATE = 'generate';

    public const KEEP = 'keep';

    public const CARD = 'card';

    /** Newly hired / Rehired: a generated number ends in -RH for a rehire. */
    public bool $rehired = false;

    /** Only typed in "card" mode; otherwise generated, or the saved one. */
    public string $employee_number = '';

    public string $name = '';

    public string $email = '';

    /** The client site (detachment). Its name is copied into `detachment`. */
    public string $client_id = '';

    /** Office staff: the OSSI office they work at (where their clock-in must be). */
    public string $office_id = '';

    /**
     * Region and province narrow the Detachment list (a Region value, a province name).
     * Not saved: the client carries its own region and province.
     */
    public string $region = '';

    public string $province = '';

    /**
     * A detachment typed before clients existed (no client_id). Kept as it is
     * until HR picks the client, so editing a phone number doesn't erase it.
     */
    public ?string $legacyDetachment = null;

    public string $post = '';

    public string $position = '';

    public string $hire_date = '';

    public string $employment_status = 'probationary';

    /** A shift's id, '' for the default shift, or self::CUSTOM_SHIFT to type the times. */
    public string $shift_id = '';

    public const CUSTOM_SHIFT = 'custom';

    /** Typed times when shift_id is "custom" (HH:MM, 24-hour). */
    public string $custom_start = '';

    public string $custom_end = '';

    public string $custom_grace = '10';

    public bool $is_office_staff = false;

    /**
     * A tester without a real email (chosen when adding only): the email is made from
     * the employee number (EmployeeProfile::testEmailFor) and confirmed on registering.
     */
    public bool $is_test_account = false;

    /**
     * Province of birth: gives the number's 3 letters (Provinces). Not a column:
     * the number holds it, and this follows its letters on edit.
     */
    public string $birth_province = '';

    public string $contact_number = '';

    public string $permanent_address = '';

    public string $birth_date = '';

    /** They objected to the birthday reminder: nobody is told. */
    public bool $birthday_private = false;

    public string $sss_number = '';

    public string $philhealth_number = '';

    public string $pagibig_number = '';

    public string $tin_number = '';

    /**
     * Other IDs, as rows of name and number (blank rows are dropped on save).
     *
     * @var list<array{label: string, number: string}>
     */
    public array $other_ids = [];

    public string $emergency_contact_name = '';

    public string $emergency_contact_phone = '';

    public string $blood_type = '';

    public string $medical_conditions = '';

    public function fillFrom(EmployeeProfile $profile): void
    {
        $this->profileId = $profile->id;
        $this->employee_number = $profile->employee_number;
        $this->savedEmployeeNumber = $profile->employee_number;
        $this->numberSource = self::KEEP;
        $this->rehired = EmployeeNumber::isRehire($profile->employee_number);
        $this->birth_province = (string) EmployeeNumber::provinceCode($profile->employee_number);
        $this->name = $profile->name;
        $this->email = $profile->email;
        $this->client_id = (string) $profile->client_id;
        $this->office_id = (string) $profile->office_id;
        $profile->loadMissing('client');
        $this->region = (string) $profile->client?->region->value;
        $this->province = (string) $profile->client?->province;
        $this->legacyDetachment = $profile->client_id === null && filled($profile->detachment) ? $profile->detachment : null;
        $this->post = (string) $profile->post;
        $this->position = (string) $profile->position?->value;
        $this->hire_date = (string) $profile->hire_date?->toDateString();
        $this->employment_status = $profile->employment_status->value;
        $this->useShiftTimes($profile->shift);
        $this->is_office_staff = $profile->is_office_staff;
        $this->is_test_account = $profile->is_test_account;
        $this->contact_number = (string) $profile->contact_number;
        $this->permanent_address = (string) $profile->permanent_address;
        $this->birth_date = (string) $profile->birthDate()?->toDateString();
        $this->birthday_private = $profile->birthday_private;
        $this->sss_number = (string) $profile->sss_number;
        $this->philhealth_number = (string) $profile->philhealth_number;
        $this->pagibig_number = (string) $profile->pagibig_number;
        $this->tin_number = (string) $profile->tin_number;
        $this->other_ids = $profile->other_ids ?? [];
        $this->emergency_contact_name = (string) $profile->emergency_contact_name;
        $this->emergency_contact_phone = (string) $profile->emergency_contact_phone;
        $this->blood_type = (string) $profile->blood_type;
        $this->medical_conditions = (string) $profile->medical_conditions;
    }

    /**
     * The statuses HR can pick. Resigned and Terminated aren't picked: they come from the
     * resignation flow, a Notice to Explain decided as dismissal, or a failed probation
     * (owner, 2026-10-24). A record already Resigned or Terminated keeps that status listed,
     * read from the database (never from the browser), so it can still be saved.
     *
     * @return list<EmploymentStatus>
     */
    public function statusChoices(): array
    {
        $current = $this->profileId === null ? null : EmployeeProfile::query()->whereKey($this->profileId)->value('employment_status');
        $current = $current instanceof EmploymentStatus ? $current : EmploymentStatus::tryFrom((string) $current);

        return $current !== null && ! $current->isActive() ? [...EmploymentStatus::active(), $current] : EmploymentStatus::active();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            // Generated or kept: nothing typed to check. A card's number: the format, and that
            // it fits the hire date and province of birth on the record (both filled from it).
            'employee_number' => $this->numberSource !== self::CARD ? ['exclude'] : ['required', 'string', 'max:50',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value === $this->savedEmployeeNumber) {
                        return;
                    }

                    if (! EmployeeNumber::isValid((string) $value)) {
                        $fail(EmployeeNumber::HELP);
                    } elseif ($this->hire_date !== '' && EmployeeNumber::hireDate((string) $value)?->toDateString() !== $this->hire_date) {
                        $fail('This card number is for a hire date of '.EmployeeNumber::hireDate((string) $value)?->format('M j, Y').', not the hire date below.');
                    } elseif ($this->birth_province !== '' && EmployeeNumber::provinceCode((string) $value) !== $this->birth_province) {
                        $fail('This card number is for '.Provinces::name((string) EmployeeNumber::provinceCode((string) $value)).', not the province of birth chosen.');
                    }
                },
                Rule::unique('employee_profiles', 'employee_number')->ignore($this->profileId)],
            'name' => ['required', 'string', 'max:255', 'regex:'.PhilippineFormats::NAME_PATTERN],
            // The email the employee must register with. A test account's is made from the number.
            'email' => $this->is_test_account ? ['exclude'] : ['required', 'email', 'max:255', Rule::unique('employee_profiles', 'email')->ignore($this->profileId)],
            // Chosen when adding; an existing record stays what it is.
            'is_test_account' => $this->profileId === null ? ['boolean'] : ['exclude'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'office_id' => ['nullable', 'integer', 'exists:offices,id'],
            'post' => ['nullable', 'string', 'max:255'],
            // Only positions that fit the staff type: no "Security Guard" in the office.
            'position' => ['nullable', Rule::in(array_map(fn (Position $position) => $position->value, Position::for($this->is_office_staff)))],
            // The number is made from these two.
            'hire_date' => $this->numberSource === self::GENERATE ? ['required', 'date'] : ['nullable', 'date'],
            'employment_status' => ['required', Rule::in(array_map(fn (EmploymentStatus $status) => $status->value, $this->statusChoices()))],
            // A listed shift, or "custom" with the times typed below.
            'shift_id' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== self::CUSTOM_SHIFT && ! Shift::query()->whereKey((int) $value)->exists()) {
                    $fail('Choose a shift from the list, or "Custom time".');
                }
            }],
            'custom_start' => ['exclude_unless:shift_id,'.self::CUSTOM_SHIFT, 'required', 'date_format:H:i'],
            'custom_end' => ['exclude_unless:shift_id,'.self::CUSTOM_SHIFT, 'required', 'date_format:H:i', 'different:custom_start'],
            'custom_grace' => ['exclude_unless:shift_id,'.self::CUSTOM_SHIFT, 'required', 'integer', 'min:0', 'max:120'],
            'is_office_staff' => ['boolean'],
            'birth_province' => [$this->numberSource === self::GENERATE ? 'required' : 'nullable', Rule::in(Provinces::codes())],
            // Their own phone: a mobile (load requests use it). The emergency contact may be a landline.
            'contact_number' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (PhilippineFormats::mobileNumber((string) $value) === null) {
                    $fail('Enter a Philippine mobile number, e.g. 0917 123 4567.');
                }
            }],
            'permanent_address' => ['nullable', 'string', 'max:255'],
            // At least 18 (no minors on staff); born 1940 or later, to catch a mistyped year.
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1940-01-01', 'before_or_equal:'.CarbonImmutable::today()->subYears(18)->toDateString()],
            'birthday_private' => ['boolean'],
            // Digits in the agency's pattern only (GovernmentId), or blank until known.
            ...array_map(fn () => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                $problem = GovernmentId::problem(str_replace('form.', '', $attribute), (string) $value);
                if ($problem !== null) {
                    $fail($problem);
                }
            }], GovernmentId::FORMATS),
            // A name needs its number and a number its name (same row).
            'other_ids' => ['array', 'max:'.EmployeeProfile::MAX_OTHER_IDS],
            'other_ids.*.label' => ['nullable', 'string', 'max:60', 'required_with:other_ids.*.number'],
            'other_ids.*.number' => ['nullable', 'string', 'max:100', 'required_with:other_ids.*.label'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255', 'regex:'.PhilippineFormats::NAME_PATTERN],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50', function (string $attribute, mixed $value, \Closure $fail) {
                if (PhilippineFormats::phoneNumber((string) $value) === null) {
                    $fail('Enter a mobile (0917 123 4567) or a landline with its area code ((02) 8123 4567).');
                }
            }],
            'blood_type' => ['nullable', Rule::in(self::BLOOD_TYPES)],
            'medical_conditions' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['name.regex' => PhilippineFormats::NAME_MESSAGE, 'emergency_contact_name.regex' => PhilippineFormats::NAME_MESSAGE,
            'birth_date.before_or_equal' => 'They must be at least 18.', 'birth_date.after_or_equal' => 'Check the year.'];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['custom_start' => 'start time', 'custom_end' => 'end time', 'custom_grace' => 'grace minutes', 'birth_province' => 'province of birth',
            'other_ids' => 'other IDs', 'other_ids.*.label' => 'ID name', 'other_ids.*.number' => 'ID number'];
    }

    /** The number so far, for the form: "260627PAM-····", or null until hire date and province are in. */
    public function numberPreview(): ?string
    {
        $date = rescue(fn () => $this->hire_date !== '' ? CarbonImmutable::parse($this->hire_date) : null, report: false);

        if ($date === null || ! Provinces::isCode($this->birth_province)) {
            return null;
        }

        return EmployeeNumber::prefix($date, $this->birth_province).'-····'.($this->rehired ? '-RH' : '');
    }

    /** Switch between a generated number and an ID card's (and back to the saved one when editing). */
    public function useNumberFrom(string $source): void
    {
        $this->numberSource = match (true) {
            $source === self::CARD => self::CARD,
            $this->profileId !== null => self::KEEP,
            default => self::GENERATE,
        };
        $this->employee_number = $this->numberSource === self::KEEP ? (string) $this->savedEmployeeNumber : '';
    }

    /**
     * A card's number typed (the page's updatedForm hook calls this): tidy it,
     * and fill the hire date and province of birth from it, so they agree.
     */
    public function numberChanged(string $field): void
    {
        if ($field !== 'employee_number' || $this->numberSource !== self::CARD) {
            return;
        }

        $this->employee_number = EmployeeNumber::normalize($this->employee_number);

        if (EmployeeNumber::isValid($this->employee_number)) {
            $this->hire_date = (string) EmployeeNumber::hireDate($this->employee_number)?->toDateString();
            $this->birth_province = (string) EmployeeNumber::provinceCode($this->employee_number);
            $this->rehired = EmployeeNumber::isRehire($this->employee_number);
        }
    }

    /**
     * Validated attributes for SaveEmployeeProfile, with "" turned into null.
     *
     * @return array<string, mixed>
     */
    public function toProfileAttributes(): array
    {
        // Ticking or unticking "Office staff" can leave a position of the other
        // kind selected; drop it instead of failing validation on a hidden choice.
        if (! in_array(Position::tryFrom($this->position), Position::for($this->is_office_staff), true)) {
            $this->position = '';
        }

        // Test account is chosen when adding: an existing record keeps what it was saved as,
        // whatever the browser sends (its email stays the one made from the number).
        if ($this->profileId !== null) {
            $this->is_test_account = (bool) EmployeeProfile::query()->whereKey($this->profileId)->value('is_test_account');
        }

        $this->employee_number = EmployeeNumber::normalize($this->employee_number);
        // "34 1234567 8" or "3412345678" is saved as 34-1234567-8.
        foreach (array_keys(GovernmentId::FORMATS) as $field) {
            $this->{$field} = GovernmentId::format($field, $this->{$field});
        }
        // "+63 917-123-4567" is saved as 0917 123 4567; names lose stray spaces.
        $this->contact_number = PhilippineFormats::readableMobile($this->contact_number) ?? trim($this->contact_number);
        $this->emergency_contact_phone = PhilippineFormats::phoneNumber($this->emergency_contact_phone) ?? trim($this->emergency_contact_phone);
        $this->name = PhilippineFormats::name($this->name);
        $this->emergency_contact_name = PhilippineFormats::name($this->emergency_contact_name);
        $validated = $this->validate();

        // Other IDs: trimmed, blank rows dropped, none at all saved as null.
        $otherIds = [];
        foreach ($validated['other_ids'] ?? [] as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $number = trim((string) ($row['number'] ?? ''));
            if ($label !== '' && $number !== '') {
                $otherIds[] = ['label' => $label, 'number' => $number];
            }
        }
        $validated['other_ids'] = $otherIds === [] ? null : $otherIds;

        // A new record's number is made here, from what was just validated.
        if ($this->numberSource === self::GENERATE) {
            $validated['employee_number'] = EmployeeNumber::generate(CarbonImmutable::parse($this->hire_date), $this->birth_province, $this->rehired);
        }
        // The shift is passed separately (chosenShift()), not as a profile column.
        unset($validated['shift_id'], $validated['custom_start'], $validated['custom_end'], $validated['custom_grace'], $validated['birth_province']);

        // A test account's email follows its number (a kept number keeps the saved email).
        if ($this->is_test_account) {
            if (isset($validated['employee_number'])) {
                $validated['email'] = EmployeeProfile::testEmailFor((string) $validated['employee_number']);
            }
        } else {
            $validated['email'] = strtolower(trim((string) $validated['email']));
        }

        // Office staff are not deployed anywhere: clear what a guard record may have left behind.
        // Guards work at a client, not an office.
        if ($validated['is_office_staff']) {
            $validated['client_id'] = null;
            $validated['post'] = null;
            $validated['office_id'] = filled($validated['office_id']) ? (int) $validated['office_id'] : null;
        } else {
            $validated['office_id'] = null;
        }

        // The detachment shown everywhere is the client's name.
        $validated['client_id'] = filled($validated['client_id']) ? (int) $validated['client_id'] : null;
        $validated['detachment'] = match (true) {
            $validated['client_id'] !== null => Client::query()->whereKey($validated['client_id'])->value('name'),
            (bool) $validated['is_office_staff'] => null,
            default => $this->legacyDetachment,
        };

        return array_map(fn (mixed $value) => $value === '' ? null : $value, $validated);
    }

    /**
     * Keeps region → province → detachment consistent as HR picks (the page's
     * updatedForm hook calls this): a new region clears the province, a narrower
     * area drops a detachment outside it, and choosing a detachment fills in its area.
     */
    public function areaChanged(string $field): void
    {
        if ($field === 'region') {
            $this->province = '';
        }

        if ($field === 'region' || $field === 'province') {
            if ($this->client_id !== '' && ! $this->clientsInArea()->contains('id', (int) $this->client_id)) {
                $this->client_id = '';
            }

            return;
        }

        if ($field === 'client_id' && $this->client_id !== '') {
            $client = Client::query()->find((int) $this->client_id);
            $this->region = (string) $client?->region->value;
            $this->province = (string) $client?->province;
        }
    }

    /**
     * Regions that have at least one client, in the enum's order (NCR, CAR, Region I…).
     *
     * @return list<Region>
     */
    public function regionsWithClients(): array
    {
        $used = Client::query()->distinct()->pluck('region')->map(fn (Region $region) => $region->value)->all();

        return array_values(array_filter(Region::cases(), fn (Region $region) => in_array($region->value, $used, true)));
    }

    /**
     * Provinces of the chosen region that have clients.
     *
     * @return list<string>
     */
    public function provincesInRegion(): array
    {
        if ($this->region === '') {
            return [];
        }

        return array_values(Client::query()->where('region', $this->region)->orderBy('province')->get(['province'])
            ->map(fn (Client $client): string => $client->province)
            ->filter()->unique()->all());
    }

    /**
     * The OSSI offices an office staffer can work at.
     *
     * @return EloquentCollection<int, Office>
     */
    public function offices(): EloquentCollection
    {
        return Office::query()->orderBy('name')->get();
    }

    /**
     * The detachments to choose from: the chosen region and province, or all of them.
     *
     * @return EloquentCollection<int, Client>
     */
    public function clientsInArea(): EloquentCollection
    {
        return Client::query()
            ->when($this->region !== '', fn ($query) => $query->where('region', $this->region))
            ->when($this->province !== '', fn ($query) => $query->where('province', $this->province))
            ->orderBy('region')->orderBy('province')->orderBy('name')
            ->get();
    }

    /**
     * HR types the hours (no list to pick from): start from this shift's
     * times, or the company default's for a record without one.
     */
    public function useShiftTimes(?Shift $shift): void
    {
        $shift ??= Shift::fallback();

        $this->shift_id = self::CUSTOM_SHIFT;
        $this->custom_start = substr($shift->start_time, 0, 5);
        $this->custom_end = substr($shift->end_time, 0, 5);
        $this->custom_grace = (string) $shift->grace_minutes;
    }

    public function shiftId(): ?int
    {
        return $this->shift_id !== '' && $this->shift_id !== self::CUSTOM_SHIFT ? (int) $this->shift_id : null;
    }

    /**
     * The shift to save on the record, after toProfileAttributes() validated:
     * the one picked, the default (null), or the one with the typed times
     * (reused when it exists, created otherwise).
     */
    public function chosenShift(ShiftForTimes $shiftForTimes, User $actor): ?Shift
    {
        if ($this->shift_id === self::CUSTOM_SHIFT) {
            return $shiftForTimes->handle($this->custom_start, $this->custom_end, (int) $this->custom_grace, $actor);
        }

        return Shift::query()->find($this->shiftId());
    }
}
