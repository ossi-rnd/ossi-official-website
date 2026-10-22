<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each person hears only their own notifications.
Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);
