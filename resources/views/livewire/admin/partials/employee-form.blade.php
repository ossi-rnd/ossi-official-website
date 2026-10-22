{{-- Shared by "Add employee" and "Edit employee". Fields bind to the EmployeeProfileForm object ($form). --}}
<div class="space-y-4">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.input label="Full name" wire:model="form.name" placeholder="e.g. Juan Dela Cruz" autocapitalize="words" maxlength="255"
            x-on:input="$el.value = $el.value.replace(/[^\p{L}\p{M} .,'-]/gu, '')" />
        @if ($form->is_test_account)
            {{-- A tester without a real mailbox: the email is made from the number (EmployeeProfile::testEmailFor). --}}
            <div>
                <p class="ui-label">Email</p>
                @php $numberSoFar = $form->numberSource === \App\Livewire\Forms\EmployeeProfileForm::CARD ? $form->employee_number : $form->numberPreview(); @endphp
                <p class="mt-1 flex min-h-10 items-center font-mono text-sm text-gray-800 dark:text-gray-100">
                    @if ($form->profileId !== null)
                        {{ $form->email }}
                    @elseif (filled($numberSoFar))
                        {{ \App\Models\EmployeeProfile::testEmailFor($numberSoFar) }}
                    @else
                        <span class="font-sans text-gray-500 dark:text-gray-400">Shown once the employee number is made</span>
                    @endif
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Test account: made from the employee number. They register with it; no email is ever sent there.</p>
            </div>
        @else
            <x-ui.input label="Email" type="email" wire:model="form.email" hint="The only email this person can register with." />
        @endif
    </div>
    @if ($form->profileId === null)
        <x-ui.switch id="form-test-account" wire:model.live="form.is_test_account" label="Test account (no real email)"
            description="For pilot testers. The email is made from the employee number, and they won't need to confirm it." />
    @endif

    {{-- The employee number is the OSSI ID card's control number: hire date (YYMMDD) + province
         of birth code + 4 random digits (+ -RH). Generated on save from the two fields below
         (EmployeeNumber::generate), kept as it is when editing, or typed from a card already printed. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.input label="Hire date" type="date" wire:model.live="form.hire_date" />
        {{-- Kept number: the province lives in it, so it's shown, not changed (a pick would save nowhere). --}}
        @if ($form->numberSource === \App\Livewire\Forms\EmployeeProfileForm::KEEP)
            <div>
                <p class="ui-label">Province of birth</p>
                <p class="mt-1 flex min-h-10 items-center text-sm text-gray-800 dark:text-gray-100">{{ $form->birth_province !== '' ? \App\Support\Provinces::name($form->birth_province).' · '.$form->birth_province : 'Not in this number' }}</p>
            </div>
        @else
            <x-ui.select label="Province of birth" wire:model.live="form.birth_province" searchable>
                <option value="">Select province</option>
                @foreach (\App\Support\Provinces::ALL as $code => $provinceName)
                    <option value="{{ $code }}">{{ $provinceName }} · {{ $code }}</option>
                @endforeach
            </x-ui.select>
        @endif
    </div>

    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/50">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div class="min-w-0">
                <p class="ui-label">Employee number</p>
                @if ($form->numberSource === \App\Livewire\Forms\EmployeeProfileForm::CARD)
                    <div class="mt-1 w-full sm:w-64">
                        <x-ui.input name="form.employee_number" wire:model.live.blur="form.employee_number" placeholder="{{ \App\Support\EmployeeNumber::EXAMPLE }}" maxlength="20" autocomplete="off" spellcheck="false"
                            x-on:input="$el.value = formatEmployeeNumber($el.value)" aria-label="Number on the ID card" />
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">As printed on their OSSI ID card. The hire date and province of birth are filled in from it.</p>
                @elseif ($form->numberSource === \App\Livewire\Forms\EmployeeProfileForm::KEEP)
                    <p class="mt-1 font-mono text-base font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $form->employee_number }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">It doesn't change: it's on their ID card and they registered with it.</p>
                @elseif ($preview = $form->numberPreview())
                    <p class="mt-1 font-mono text-base font-semibold text-gray-800 tabular-nums dark:text-gray-100">{{ $preview }}</p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Made from the hire date and province of birth. The last 4 digits are picked when you save.</p>
                @else
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Choose the hire date and province of birth, and it's made for you.</p>
                @endif
            </div>

            {{-- Rehired adds -RH to a generated number (a card's number already says it). --}}
            @if ($form->numberSource === \App\Livewire\Forms\EmployeeProfileForm::GENERATE)
                <div class="inline-flex shrink-0 rounded-lg border border-gray-300 bg-white p-0.5 dark:border-gray-700 dark:bg-gray-900" role="radiogroup" aria-label="Hire type">
                    @foreach ([false => 'Newly hired', true => 'Rehired'] as $rehire => $label)
                        <button type="button" role="radio" wire:click="$set('form.rehired', {{ $rehire ? 'true' : 'false' }})" aria-checked="{{ $form->rehired === (bool) $rehire ? 'true' : 'false' }}"
                            @class(['rounded-md px-3 py-1 text-xs font-medium whitespace-nowrap', 'bg-blue-600 text-white' => $form->rehired === (bool) $rehire, 'text-gray-600 hover:text-gray-800 dark:text-gray-300 dark:hover:text-gray-100' => $form->rehired !== (bool) $rehire])>{{ $label }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="mt-2 border-t border-gray-200 pt-2 text-xs dark:border-gray-700">
            @if ($form->numberSource === \App\Livewire\Forms\EmployeeProfileForm::CARD)
                <button type="button" wire:click="useNumberFrom('{{ $form->profileId ? 'keep' : 'generate' }}')" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                    {{ $form->profileId ? 'Keep the current number' : 'Generate it instead' }}
                </button>
            @else
                <span class="text-gray-500 dark:text-gray-400">{{ $form->profileId ? 'Their ID card has a different number?' : 'Already has an OSSI ID card?' }}</span>
                <button type="button" wire:click="useNumberFrom('card')" class="font-medium text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">Enter the card's number</button>
            @endif
        </div>
    </div>

    <p class="ui-section-label pt-2">Assignment</p>
    {{-- First, because it decides which of the fields below apply. A two-option choice, not a checkbox: both answers are named. --}}
    <div role="radiogroup" aria-label="Staff type" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        @foreach ([
            false => ['Field staff', 'Security personnel deployed to a detachment and post.', 'shield'],
            true => ['Office staff', 'Admin, HR, Accounting, IT or Operations. No detachment or post.', 'users'],
        ] as $office => [$title, $hint, $icon])
            @php $selected = $form->is_office_staff === (bool) $office; @endphp
            <button type="button" role="radio" aria-checked="{{ $selected ? 'true' : 'false' }}" wire:click="$set('form.is_office_staff', {{ $office ? 'true' : 'false' }})"
                @class([
                    'flex items-start gap-3 rounded-lg border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:outline-none',
                    'border-blue-500 bg-blue-50 ring-1 ring-blue-500 dark:bg-blue-500/10' => $selected,
                    'border-gray-200 bg-white hover:border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600' => ! $selected,
                ])>
                <span @class(['mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md', 'bg-blue-600 text-white' => $selected, 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300' => ! $selected])>
                    <x-icon :name="$icon" class="h-4 w-4" />
                </span>
                <span class="min-w-0">
                    <span @class(['block text-sm font-semibold', 'text-blue-700 dark:text-blue-300' => $selected, 'text-gray-800 dark:text-gray-100' => ! $selected])>{{ $title }}</span>
                    <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                </span>
            </button>
        @endforeach
    </div>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @unless ($form->is_office_staff)
            {{-- Region → province narrow the detachment list; choosing a detachment fills them in.
                 wire:key: each list changes with the one before it, so the custom select re-mounts. --}}
            {{-- Said once for the group: these three are the client's site, not the person's address. --}}
            <p class="flex flex-wrap items-center gap-x-1.5 text-xs font-semibold tracking-wide text-gray-500 uppercase sm:col-span-2 dark:text-gray-400">
                <x-icon name="building" class="h-3.5 w-3.5" /> Client site
                <span class="w-full font-normal tracking-normal normal-case text-gray-400 sm:w-auto dark:text-gray-500"><span class="hidden sm:inline">· </span>Where they're deployed, not their home address</span>
            </p>
            <div wire:key="region-select" class="-mt-2">
                <x-ui.select label="Region" wire:model.live="form.region">
                    <option value="">All regions</option>
                    @foreach ($form->regionsWithClients() as $region)
                        <option value="{{ $region->value }}">{{ $region->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div wire:key="province-select-{{ $form->region }}" class="sm:-mt-2">
                <x-ui.select label="Province" wire:model.live="form.province">
                    <option value="">{{ $form->region === '' ? 'Choose a region first' : 'All provinces' }}</option>
                    @foreach ($form->provincesInRegion() as $province)
                        <option value="{{ $province }}">{{ $province }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            @php $areaClients = $form->clientsInArea(); @endphp
            <div wire:key="client-select-{{ $form->region }}-{{ $form->province }}">
                <x-ui.select label="Detachment" wire:model.live="form.client_id">
                    <option value="">{{ $areaClients->isEmpty() ? 'No clients in this area' : 'Not deployed yet' }}</option>
                    @foreach ($areaClients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}{{ $form->province === '' ? ' · '.$client->locationLabel() : '' }}</option>
                    @endforeach
                </x-ui.select>
                @if ($form->legacyDetachment && $form->client_id === '')
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">Currently "{{ $form->legacyDetachment }}" (typed before clients were set up). Pick its client to file it under a region.</p>
                @else
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Their home address is under Contact. Operation HQ or Admin adds clients under Clients.</p>
                @endif
            </div>
            <x-ui.input label="Post" wire:model="form.post" />
        @else
            {{-- Office staff: the OSSI office they work at; their clock-in must be inside its circle. --}}
            <div wire:key="office-select">
                <x-ui.select label="Office" wire:model="form.office_id">
                    <option value="">{{ $form->offices()->isEmpty() ? 'No offices yet' : 'No office (can clock in anywhere)' }}</option>
                    @foreach ($form->offices() as $office)
                        <option value="{{ $office->id }}">{{ $office->name }} · {{ $office->locationLabel() }}</option>
                    @endforeach
                </x-ui.select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">They clock in within the office's circle. Admin adds offices under OSSI Offices.</p>
            </div>
        @endunless
        {{-- wire:key: the option list changes with the staff type, so the custom select must re-mount. --}}
        <div wire:key="position-{{ $form->is_office_staff ? 'office' : 'field' }}">
            <x-ui.select label="Position" wire:model="form.position">
                <option value="">Select position</option>
                @foreach (\App\Enums\Position::for($form->is_office_staff) as $position)
                    <option value="{{ $position->value }}">{{ $position->label() }}</option>
                @endforeach
            </x-ui.select>
        </div>
        <div>
            {{-- HR types the hours (or taps common ones): saved as the shift with exactly these times,
                 reused when one exists (e.g. 06:00–15:00 is "Day Duty"). Starts from their current shift. --}}
            <p class="ui-label">Shift hours</p>
            <x-shift-hours class="mt-1" wire:key="custom-shift" start="form.custom_start" end="form.custom_end" grace="form.custom_grace" />
        </div>
        <x-ui.select label="Employment status" wire:model="form.employment_status">
            @foreach (\App\Enums\EmploymentStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-ui.select>
    </div>

    <p class="ui-section-label pt-2">Contact</p>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {{-- Phones keep only digits, spaces, + ( ) -; names only letters and . , ' - (PhilippineFormats checks the same on save). --}}
        <x-ui.input label="Contact number" wire:model="form.contact_number" type="tel" inputmode="tel" placeholder="0917 123 4567" maxlength="20"
            x-on:input="$el.value = $el.value.replace(/[^\d\s()+-]/g, '')" />
        <x-ui.input label="Permanent address" wire:model="form.permanent_address" placeholder="House no., street, barangay, city, province" maxlength="255" />
        <x-ui.input label="Emergency contact name" wire:model="form.emergency_contact_name" placeholder="e.g. Maria Dela Cruz" autocapitalize="words" maxlength="255"
            x-on:input="$el.value = $el.value.replace(/[^\p{L}\p{M} .,'-]/gu, '')" />
        <x-ui.input label="Emergency contact phone" wire:model="form.emergency_contact_phone" type="tel" inputmode="tel" placeholder="0917 123 4567 or (02) 8123 4567" maxlength="20"
            x-on:input="$el.value = $el.value.replace(/[^\d\s()+-]/g, '')" />
        {{-- Encrypted (it reveals age). HR and Operations get a birthday reminder, day and month only. --}}
        <x-ui.input label="Date of birth" type="date" wire:model="form.birth_date" :max="today()->subYears(18)->toDateString()" min="1940-01-01" />
        <div class="sm:pt-7">
            <x-ui.switch id="form-birthday-private" wire:model="form.birthday_private" label="Keep their birthday private"
                description="They asked not to be greeted: no birthday reminder, and not on the birthday list." />
        </div>
    </div>

    <p class="ui-section-label pt-2">Government IDs <span class="font-normal normal-case">(encrypted at rest)</span></p>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {{-- Digits only, dashed as typed in the agency's pattern (App\Support\GovernmentId). --}}
        @foreach (\App\Support\GovernmentId::FORMATS as $field => $format)
            <x-ui.input :label="$format['label']" wire:model="form.{{ $field }}" autocomplete="off" inputmode="numeric"
                :placeholder="explode(' or ', $format['example'])[0]" :hint="$field === 'tin_number' ? 'Add the branch code too if the card shows one.' : null"
                x-on:input="$el.value = formatGovernmentId($el.value, {{ json_encode($format['groups']) }})" />
        @endforeach
    </div>

    {{-- Any other ID (driver's license, passport, a guard license…): name + number rows, encrypted
         like the four above. Rows are added and removed on the form; blank rows are dropped on save. --}}
    <div>
        <p class="ui-label">Other IDs <span class="font-normal text-gray-500 dark:text-gray-400">(optional)</span></p>
        <datalist id="other-id-names">
            @foreach (\App\Models\EmployeeProfile::OTHER_ID_SUGGESTIONS as $suggestion)
                <option value="{{ $suggestion }}"></option>
            @endforeach
        </datalist>
        <div class="mt-1 space-y-2">
            @foreach ($form->other_ids as $index => $row)
                <div wire:key="other-id-{{ $index }}" class="grid grid-cols-[1fr_auto] gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    <x-ui.input wire:model="form.other_ids.{{ $index }}.label" list="other-id-names" placeholder="ID name, e.g. Driver's License" aria-label="ID name {{ $index + 1 }}" autocomplete="off" />
                    <div class="col-start-1 row-start-2 sm:col-start-auto sm:row-start-auto">
                        <x-ui.input wire:model="form.other_ids.{{ $index }}.number" placeholder="ID number" aria-label="ID number {{ $index + 1 }}" autocomplete="off" />
                    </div>
                    <button type="button" x-on:click="$wire.$set('form.other_ids', $wire.form.other_ids.filter((row, at) => at !== {{ $index }}))"
                        aria-label="Remove ID {{ $index + 1 }}" class="col-start-2 row-start-1 mt-1 h-9 self-start rounded-md px-2 text-gray-400 hover:bg-gray-100 hover:text-red-600 sm:col-start-auto sm:row-start-auto dark:hover:bg-gray-800 dark:hover:text-red-400">
                        <x-icon name="trash" class="h-4 w-4" />
                    </button>
                </div>
            @endforeach
        </div>
        @if (count($form->other_ids) < \App\Models\EmployeeProfile::MAX_OTHER_IDS)
            <button type="button" x-on:click="$wire.$set('form.other_ids', [...$wire.form.other_ids, { label: '', number: '' }])"
                class="mt-2 inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm font-medium text-blue-600 hover:bg-blue-50 hover:text-blue-500 dark:text-blue-400 dark:hover:bg-blue-500/10 dark:hover:text-blue-300">
                <x-icon name="plus" class="h-4 w-4" /> Add another ID
            </button>
        @endif
        @error('form.other_ids') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <p class="ui-section-label pt-2">Medical <span class="font-normal normal-case">(private · conditions encrypted at rest)</span></p>
    <div class="w-full sm:w-48">
        <x-ui.select label="Blood type" wire:model="form.blood_type">
            <option value="">Not known</option>
            @foreach (\App\Livewire\Forms\EmployeeProfileForm::BLOOD_TYPES as $type)
                <option value="{{ $type }}">{{ $type }}</option>
            @endforeach
        </x-ui.select>
    </div>
    <x-ui.textarea label="Conditions and allergies" wire:model="form.medical_conditions" rows="3"
        hint="For emergencies on duty, e.g. asthma, allergic to penicillin, maintenance medicine for hypertension. Leave blank if none." />
</div>
