<?php

namespace App\Livewire\Profile;

use App\Actions\Profile\UpdateAvatar;
use App\Livewire\Concerns\InteractsWithUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The one Livewire piece of Account Settings: Fortify has no avatar feature.
 */
class AvatarForm extends Component
{
    use InteractsWithUser, WithFileUploads;

    /** @var UploadedFile|null */
    public $avatar = null;

    public function save(UpdateAvatar $updateAvatar): void
    {
        $this->validate(['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        /** @var UploadedFile $avatar */
        $avatar = $this->avatar;
        $updateAvatar->handle($this->currentUser(), $avatar);

        $this->reset('avatar');
        $this->dispatch('notify', message: 'Profile photo updated.');
    }

    public function remove(UpdateAvatar $updateAvatar): void
    {
        $updateAvatar->handle($this->currentUser(), null);

        $this->dispatch('notify', message: 'Profile photo removed.');
    }

    public function render(): View
    {
        return view('livewire.profile.avatar-form', ['user' => $this->currentUser()]);
    }
}
