<?php

use App\Actions\Attendance\ForgetGuardPhone;
use App\Actions\Attendance\RecordPunch;
use App\Actions\Attendance\SetGuardPhone;
use App\Enums\PunchType;
use App\Enums\Region;
use App\Enums\Role;
use App\Livewire\Admin\AttendanceLog;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Attendance\ClockPanel;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\TimeLog;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\OtherPhoneUsed;
use App\Support\DeviceCookie;
use App\Support\DeviceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Guards are given a company phone. Their first clock-in registers it; a punch from
// another phone still counts, and is flagged to Operation HQ, the region's Satellite
// Operation and HR (the bell), once a day per phone.

const COMPANY_PHONE = 'companyphonecode000000000000000000000001';
const OTHER_PHONE = 'borrowedphonecode00000000000000000000002';

function punchOn(User $user, PunchType $type, ?string $token, string $label = 'Android · Chrome'): TimeLog
{
    return app(RecordPunch::class)->handle($user, $type, cameraPhoto(), 14.5869, 121.0614, 8, 'Ortigas Center', $token, $label);
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:05')); // Default shift 09:00–18:00.
    $site = Client::query()->create(['region' => Region::Calabarzon, 'province' => 'Cavite', 'name' => 'Metrobank – Imus']);
    $this->guard = employee(['client_id' => $site->id]);
    $this->hq = staff(Role::OperationsHq);
    $this->hr = staff(Role::Hr);
    $this->satellite = staff(Role::SatelliteOperations);
    $this->satellite->forceFill(['operations_region' => Region::Calabarzon])->save();
    $this->otherSatellite = staff(Role::SatelliteOperations);
    $this->otherSatellite->forceFill(['operations_region' => Region::Ncr])->save();
});

it('registers the phone of the first clock-in, and doesn\'t flag it after', function () {
    punchOn($this->guard, PunchType::ClockIn, COMPANY_PHONE);
    $this->travel(9)->hours();
    $log = punchOn($this->guard, PunchType::ClockOut, COMPANY_PHONE);

    $device = $this->guard->device()->sole();
    expect($device->token_hash)->toBe(hash('sha256', COMPANY_PHONE))
        ->and($device->token_hash)->not->toBe(COMPANY_PHONE) // only the hash is kept
        ->and($device->label)->toBe('Android · Chrome')
        ->and($device->registered_by)->toBeNull()
        ->and($log->punches()->where('other_device', true)->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('flags another phone without refusing it, and tells Operations and HR once a day', function () {
    UserDevice::query()->forceCreate(['user_id' => $this->guard->id, 'token_hash' => UserDevice::hash(COMPANY_PHONE), 'label' => 'iPhone · Safari', 'registered_at' => now()]);

    $log = punchOn($this->guard, PunchType::ClockIn, OTHER_PHONE);

    $punch = $log->punches()->sole();
    expect($log->clock_in_at)->not->toBeNull() // the punch counts
        ->and($punch->other_device)->toBeTrue()
        ->and($punch->device_label)->toBe('Android · Chrome');

    Notification::assertSentTo([$this->hq, $this->hr, $this->satellite], OtherPhoneUsed::class, function (OtherPhoneUsed $alert) {
        $bell = $alert->toArray($this->hq);

        return str_contains($bell['title'], $this->guard->name)
            && str_contains($bell['body'], 'on Android · Chrome, not their phone (iPhone · Safari).');
    });
    Notification::assertNotSentTo([$this->otherSatellite, $this->guard], OtherPhoneUsed::class);
    expect(ActivityLog::query()->where('event', 'attendance.other_phone')->count())->toBe(1);

    // Clocking out on the same other phone: flagged and audited again, but no second bell.
    $this->travel(9)->hours();
    punchOn($this->guard, PunchType::ClockOut, OTHER_PHONE);
    Notification::assertSentToTimes($this->hq, OtherPhoneUsed::class, 1);
    expect(ActivityLog::query()->where('event', 'attendance.other_phone')->count())->toBe(2);
});

it('flags a guard whose browser keeps no code, but never office staff', function () {
    UserDevice::query()->forceCreate(['user_id' => $this->guard->id, 'token_hash' => UserDevice::hash(COMPANY_PHONE), 'registered_at' => now()]);
    expect(punchOn($this->guard, PunchType::ClockIn, null)->punches()->sole()->other_device)->toBeTrue();

    // Office staff use their own phones: recorded, not flagged.
    $clerk = staff(Role::Accounting);
    UserDevice::query()->forceCreate(['user_id' => $clerk->id, 'token_hash' => UserDevice::hash(COMPANY_PHONE), 'registered_at' => now()]);
    $punch = punchOn($clerk, PunchType::ClockIn, OTHER_PHONE)->punches()->sole();
    expect($punch->other_device)->toBeFalse()->and($punch->device_hash)->toBe(UserDevice::hash(OTHER_PHONE));
    Notification::assertNotSentTo($this->hq, OtherPhoneUsed::class, fn (OtherPhoneUsed $alert) => $alert->punch->is($punch));
});

it('lets HR make a replacement phone theirs, but not a Satellite of another region', function () {
    UserDevice::query()->forceCreate(['user_id' => $this->guard->id, 'token_hash' => UserDevice::hash(COMPANY_PHONE), 'registered_at' => now()]);
    $punch = punchOn($this->guard, PunchType::ClockIn, OTHER_PHONE)->punches()->sole();

    expect(fn () => app(SetGuardPhone::class)->handle($this->otherSatellite, $punch))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetGuardPhone::class)->handle($this->guard, $punch))->toThrow(AuthorizationException::class);

    app(SetGuardPhone::class)->handle($this->hr, $punch);

    $device = $this->guard->device()->sole();
    expect($device->token_hash)->toBe(UserDevice::hash(OTHER_PHONE))
        ->and($device->registered_by)->toBe($this->hr->id)
        ->and($punch->fresh()?->other_device)->toBeTrue(); // history keeps its flag

    $this->travel(9)->hours();
    expect(punchOn($this->guard, PunchType::ClockOut, OTHER_PHONE)->punches()->latest('id')->first()?->other_device)->toBeFalse();
    expect(ActivityLog::query()->where('event', 'attendance.phone_set')->exists())->toBeTrue();
});

it('forgets a phone, so the next clock-in registers the new one', function () {
    UserDevice::query()->forceCreate(['user_id' => $this->guard->id, 'token_hash' => UserDevice::hash(COMPANY_PHONE), 'label' => 'Lost phone', 'registered_at' => now()]);

    Livewire::actingAs($this->satellite)->test(EmployeeDetail::class, ['profile' => $this->guard->employeeProfile])
        ->assertSee('Company phone: Lost phone')
        ->call('forgetPhone')
        ->assertSee('No phone registered yet');

    expect(punchOn($this->guard, PunchType::ClockIn, OTHER_PHONE)->punches()->sole()->other_device)->toBeFalse()
        ->and($this->guard->device()->sole()->token_hash)->toBe(UserDevice::hash(OTHER_PHONE));
    expect(fn () => app(ForgetGuardPhone::class)->handle($this->otherSatellite, $this->guard))->toThrow(AuthorizationException::class);
});

it('lists other-phone punches on the Attendance Log, with the fix at hand', function () {
    UserDevice::query()->forceCreate(['user_id' => $this->guard->id, 'token_hash' => UserDevice::hash(COMPANY_PHONE), 'label' => 'iPhone · Safari', 'registered_at' => now()]);
    $punch = punchOn($this->guard, PunchType::ClockIn, OTHER_PHONE)->punches()->sole();
    $honest = employee(['name' => 'Honest Guard']);
    $honest->forceFill(['name' => 'Honest Guard'])->save();
    punchOn($honest, PunchType::ClockIn, COMPANY_PHONE);

    Livewire::actingAs($this->hq)->test(AttendanceLog::class)
        ->assertSee('Other phone')
        ->call('showOnly', AttendanceLog::OTHER_PHONE)
        ->assertSee($this->guard->name)->assertDontSee('Honest Guard')
        ->assertSee('Their phone: iPhone · Safari.')
        ->assertSee('Make this their phone')
        ->call('makeTheirPhone', $punch->id);

    expect($this->guard->device()->sole()->token_hash)->toBe(UserDevice::hash(OTHER_PHONE));
});

it('keeps a phone\'s code in a cookie and describes the phone for people', function () {
    request()->cookies->set(DeviceCookie::NAME, COMPANY_PHONE);
    expect(DeviceCookie::token())->toBe(COMPANY_PHONE);

    request()->cookies->set(DeviceCookie::NAME, 'tampered!');
    expect(DeviceCookie::token())->toMatch('/^[A-Za-z0-9]{40}$/')->not->toBe('tampered!');

    expect(DeviceLabel::fromUserAgent('Mozilla/5.0 (Linux; Android 14; SM-A156E) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0 Mobile Safari/537.36'))->toBe('Android · Samsung Internet')
        ->and(DeviceLabel::fromUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'))->toBe('iPhone · Safari')
        ->and(DeviceLabel::fromUserAgent('Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36'))->toBe('Android · Chrome')
        ->and(DeviceLabel::fromUserAgent(null))->toBeNull();
});

it('shows the brand and model when the browser gives the model', function () {
    $chrome = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

    expect(DeviceLabel::fromUserAgent($chrome, 'SM-A156E'))->toBe('Samsung SM-A156E · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'CPH2591'))->toBe('OPPO CPH2591 · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'RMX3710'))->toBe('realme RMX3710 · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'V2310'))->toBe('vivo V2310 · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, '23021RAA2Y'))->toBe('Xiaomi 23021RAA2Y · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'Infinix X6831'))->toBe('Infinix X6831 · Chrome') // already names its brand
        ->and(DeviceLabel::fromUserAgent($chrome, 'TECNO KI5q'))->toBe('TECNO KI5q · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'Pixel 8'))->toBe('Pixel 8 · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'AB12'))->toBe('AB12 · Chrome') // unknown pattern: just the model
        // No model (iPhone, Firefox) or Chrome's placeholder "K": as before.
        ->and(DeviceLabel::fromUserAgent($chrome, ''))->toBe('Android · Chrome')
        ->and(DeviceLabel::fromUserAgent($chrome, 'K'))->toBe('Android · Chrome')
        // Sent by the browser, so only plain characters are kept.
        ->and(DeviceLabel::fromUserAgent($chrome, 'SM-A156E<script>'))->toBe('Samsung SM-A156Escript · Chrome');
});

it('records the model sent with a punch', function () {
    $this->actingAs($this->guard);
    Livewire::test(ClockPanel::class)
        ->call('submitPunch', 'clock_in', cameraPhoto(), 14.5869, 121.0614, 8, 'Ortigas Center', 'SM-A156E')
        ->assertReturned(['ok' => true, 'message' => 'Clocked in. Have a safe shift.']);

    expect($this->guard->device()->sole()->label)->toStartWith('Samsung SM-A156E');
});
