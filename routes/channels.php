<?php

use App\Broadcasting\ChatChannel;
use App\Broadcasting\UserChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{matchId}', ChatChannel::class);
Broadcast::channel('user.{userId}', UserChannel::class);
