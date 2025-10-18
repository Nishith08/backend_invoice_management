<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Notifications\SendPushNotification;

class NotificationController extends Controller
{
    public function send(Request $request)
    {
        $user = User::find($request->user_id);

        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $user->notify(new SendPushNotification(
            $request->title ?? 'Default title',
            $request->body ?? 'Default message body'
        ));

        return response()->json(['success' => true]);
    }
}
