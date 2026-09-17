<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyTicket;
use App\Models\CompanyTicketReply;
use App\Models\CompanyDriver;
use App\Models\CompanyUser;
use App\Models\CompanyRider;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanySetting;
use App\Models\Setting;
use App\Services\FCMService;
use App\Models\CompanyToken;
use App\Models\CompanyNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class TicketController extends Controller
{
    private function ticketsTableHasColumn(string $column): bool
    {
        return Schema::hasColumn('tickets', $column);
    }

    private function ticketRepliesTableExists(): bool
    {
        return Schema::hasTable('ticket_replies');
    }

    private function resolveTicketCreator(CompanyTicket $ticket): array
    {
        if ($ticket->user_type === "driver") {
            $user = CompanyDriver::find($ticket->user_id);
            $type = "driver";
        } elseif ($ticket->user_type === "rider" || $ticket->user_type === "user") {
            $user = CompanyRider::find($ticket->user_id);
            $type = "user";
        } else {
            $user = CompanyUser::find($ticket->user_id);
            $type = $ticket->user_type ?: "user";
        }

        $detail = $user ? [
            'id' => $user->id,
            'user_id' => $user->id,
            'user_type' => $type,
            'name' => $user->name ?? null,
            'email' => $user->email ?? null,
            'country_code' => $user->country_code ?? null,
            'phone_no' => $user->phone_no ?? $user->phone ?? null,
            'phone' => $user->phone ?? $user->phone_no ?? null,
            'address' => $user->address ?? null,
            'city' => $user->city ?? null,
            'status' => $user->status ?? null,
            'rating' => $user->rating ?? null,
            'device_count' => $user->device_count ?? null,
            'plate_no' => $user->plate_no ?? null,
            'vehicle_name' => $user->vehicle_name ?? null,
            'driver_license' => $user->driver_license ?? null,
            'created_at' => $user->created_at ?? null,
        ] : null;

        return [
            'name' => $user?->name ?? "Unknown",
            'type' => $type,
            'detail' => $detail,
        ];
    }

    private function resolveTicketReplies(CompanyTicket $ticket)
    {
        $replies = collect();

        if ($this->ticketRepliesTableExists()) {
            $replies = CompanyTicketReply::where('ticket_id', $ticket->id)
                ->orderBy('id', 'ASC')
                ->get()
                ->map(function ($reply) {
                    return [
                        'id' => $reply->id,
                        'message' => $reply->message,
                        'reply_by_type' => $reply->reply_by_type,
                        'reply_by_id' => $reply->reply_by_id,
                        'reply_by_name' => $reply->reply_by_name,
                        'created_at' => $reply->created_at,
                    ];
                });
        }

        if ($replies->isEmpty() && $ticket->reply_message) {
            $replies = collect([[
                'id' => null,
                'message' => $ticket->reply_message,
                'reply_by_type' => $ticket->reply_by_type ?? 'company',
                'reply_by_id' => $ticket->reply_by_id ?? null,
                'reply_by_name' => $ticket->reply_by_name ?? 'Company',
                'created_at' => $ticket->replied_at ?? $ticket->updated_at,
            ]]);
        }

        return $replies;
    }

    private function notifyTicketReplySocket(Request $request, CompanyTicket $ticket, array $reply, int $replyCount): void
    {
        $socketUrl = rtrim((string) config('services.node_socket.url'), '/');
        $socketSecret = (string) config('services.node_socket.internal_secret');
        $database = $request->header('database')
            ?: $request->header('x-database')
            ?: $request->input('database')
            ?: $request->input('tenantDb');

        if ($socketUrl === '' || $socketSecret === '' || !$database) {
            return;
        }

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $socketSecret,
                'database' => $database,
            ])->timeout(5)->post($socketUrl . '/ticket-reply', [
                'database' => $database,
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->ticket_id,
                'reply' => $reply,
                'reply_count' => $replyCount,
                'target_user_type' => $ticket->user_type === 'driver' ? 'driver' : 'user',
                'target_user_id' => $ticket->user_id,
            ]);
        } catch (\Throwable $socketException) {
            \Log::warning('Ticket reply socket delivery failed', [
                'ticket_id' => $ticket->id,
                'error' => $socketException->getMessage(),
            ]);
        }
    }

    private function notifyTicketStatusSocket(Request $request, CompanyTicket $ticket): void
    {
        $socketUrl = rtrim((string) config('services.node_socket.url'), '/');
        $socketSecret = (string) config('services.node_socket.internal_secret');
        $database = $request->header('database')
            ?: $request->header('x-database')
            ?: $request->input('database')
            ?: $request->input('tenantDb');

        if ($socketUrl === '' || $socketSecret === '' || !$database) {
            return;
        }

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $socketSecret,
                'database' => $database,
            ])->timeout(5)->post($socketUrl . '/ticket-status-changed', [
                'database' => $database,
                'ticket_id' => $ticket->id,
                'ticket_reference' => $ticket->ticket_id,
                'status' => $ticket->status,
                'target_user_type' => $ticket->user_type === 'driver' ? 'driver' : 'user',
                'target_user_id' => $ticket->user_id,
            ]);
        } catch (\Throwable $socketException) {
            \Log::warning('Ticket status socket delivery failed', [
                'ticket_id' => $ticket->id,
                'error' => $socketException->getMessage(),
            ]);
        }
    }

    public function listTicket(Request $request){
        try{
            $query = CompanyTicket::orderBy("id", "DESC");

            if($request->filled('status') && $request->status !== "all"){
                $query->where("status", $request->status);
            }

            if($request->filled('search')){
                $search = trim($request->search);
                $query->where(function($q) use ($search){
                    $q->where("ticket_id", "LIKE", "%". $search ."%")
                        ->orWhere("subject", "LIKE", "%". $search ."%")
                        ->orWhere("message", "LIKE", "%". $search ."%")
                        ->orWhere("reply_message", "LIKE", "%". $search ."%");
                });
            }

            $perPage = (int) $request->input('perPage', 10);
            $perPage = max(1, min($perPage, 100));
            $list = $query->paginate($perPage);

            $list->getCollection()->transform(function ($ticket) {
                $creator = $this->resolveTicketCreator($ticket);
                $ticket->customer = $creator['name'];
                $ticket->user_type = $creator['type'];
                $ticket->user_detail = $creator['detail'];
                $ticket->customer_detail = $creator['detail'];
                $replies = $this->resolveTicketReplies($ticket);
                $ticket->replies = $replies->values();
                $ticket->reply_count = $replies->count();

                return $ticket;
            });

            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeTicketStatus(Request $request){
        try{
            $request->validate([
                'ticket_id' => 'required',
                'status' => 'required'
            ]);

            $ticket = CompanyTicket::where("id", $request->ticket_id)->first();
            if (!$ticket) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Ticket not found'
                ]);
            }

            $ticket->status = $request->status;
            $ticket->save();
            $this->notifyTicketStatusSocket($request, $ticket);

            $user_type = ($ticket->user_type == "driver") ? "driver" : "rider";
            
            $title = "Ticket Status Updated";
            $body = "Your ticket #" . $ticket->ticket_id . " status has been changed to " . $request->status;

            $tokens = CompanyToken::where("user_id", $ticket->user_id)
                ->where("user_type", $user_type)
                ->get();

            if ($tokens->isNotEmpty()) {
                foreach ($tokens as $token) {
                    FCMService::sendToDevice(
                        $token->fcm_token,
                        $title,
                        $body,
                        [
                            'type' => 'ticket_status_changed',
                            'ticket_id' => $ticket->id,
                            'ticket_reference' => $ticket->ticket_id,
                            'notification_target' => 'ticket',
                        ]
                    );
                }
            }

            $notification = new CompanyNotification;
            $notification->user_type = $user_type;
            $notification->user_id = $ticket->user_id;
            $notification->title = $title;
            $notification->message = $body;
            $notification->save();

            return response()->json([
                'success' => 1,
                'message' => 'Ticket status updated and notification sent successfully',
                'ticket_id' => $ticket->id,
                'status' => $ticket->status,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function replyTicket(Request $request){
        try{
            $request->validate([
                'ticket_id' => 'required',
                'reply_message' => 'required',
            ]);

            $ticket = CompanyTicket::where("id", $request->ticket_id)->first();
            if (!$ticket) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Ticket not found'
                ]);
            }

            $previousReplyMessage = $ticket->reply_message;
            $previousReplyByType = $ticket->reply_by_type ?? 'company';
            $previousReplyById = $ticket->reply_by_id ?? null;
            $previousReplyByName = $ticket->reply_by_name ?? 'Company';
            $previousRepliedAt = $ticket->replied_at ?? $ticket->updated_at;
            
            $ticket->reply_message = $request->reply_message;
            $replyByType = $request->input('reply_by_type', 'company');
            $replyById = $request->input('reply_by_id');
            $replyByName = $request->input('reply_by_name', 'Company');

            if ($this->ticketsTableHasColumn('reply_by_type')) {
                $ticket->reply_by_type = $replyByType;
            }

            if ($this->ticketsTableHasColumn('reply_by_id')) {
                $ticket->reply_by_id = $replyById;
            }

            if ($this->ticketsTableHasColumn('reply_by_name')) {
                $ticket->reply_by_name = $replyByName;
            }

            if ($this->ticketsTableHasColumn('replied_at')) {
                $ticket->replied_at = now();
            }

            $ticket->save();

            $newReplyPayload = [
                'id' => null,
                'message' => $request->reply_message,
                'reply_by_type' => $replyByType,
                'reply_by_id' => $replyById,
                'reply_by_name' => $replyByName,
                'created_at' => now(),
            ];

            if ($this->ticketRepliesTableExists()) {
                if (
                    $previousReplyMessage &&
                    !CompanyTicketReply::where('ticket_id', $ticket->id)->exists()
                ) {
                    $legacyReply = CompanyTicketReply::create([
                        'ticket_id' => $ticket->id,
                        'message' => $previousReplyMessage,
                        'reply_by_type' => $previousReplyByType,
                        'reply_by_id' => $previousReplyById,
                        'reply_by_name' => $previousReplyByName,
                    ]);
                    $legacyReply->created_at = $previousRepliedAt;
                    $legacyReply->updated_at = $previousRepliedAt;
                    $legacyReply->save();
                }

                $newReply = CompanyTicketReply::create([
                    'ticket_id' => $ticket->id,
                    'message' => $request->reply_message,
                    'reply_by_type' => $replyByType,
                    'reply_by_id' => $replyById,
                    'reply_by_name' => $replyByName,
                ]);

                $newReplyPayload = [
                    'id' => $newReply->id,
                    'message' => $newReply->message,
                    'reply_by_type' => $newReply->reply_by_type,
                    'reply_by_id' => $newReply->reply_by_id,
                    'reply_by_name' => $newReply->reply_by_name,
                    'created_at' => $newReply->created_at,
                ];
            }

            $replyCount = $this->resolveTicketReplies($ticket)->count();
            $this->notifyTicketReplySocket($request, $ticket, $newReplyPayload, $replyCount);

            $user_type = ($ticket->user_type == "driver") ? "driver" : "rider";
            
            $title = "Ticket Reply Received";
            $body = "You have received a reply on your ticket #" . $ticket->ticket_id;

            $tokens = CompanyToken::where("user_id", $ticket->user_id)
                ->where("user_type", $user_type)
                ->get();

            if ($tokens->isNotEmpty()) {
                foreach ($tokens as $token) {
                    FCMService::sendToDevice(
                        $token->fcm_token,
                        $title,
                        $body,
                        [
                            'type' => 'ticket_reply',
                            'ticket_id' => $ticket->id,
                            'ticket_reference' => $ticket->ticket_id,
                            'notification_target' => 'ticket',
                        ]
                    );
                }
            }

            $notification = new CompanyNotification;
            $notification->user_type = $user_type;
            $notification->user_id = $ticket->user_id;
            $notification->title = $title;
            $notification->message = $body;
            $notification->save();

            return response()->json([
                'success' => 1,
                'message' => "Message updated and notification sent successfully",
                'ticket_id' => $ticket->id,
                'reply' => $newReplyPayload,
                'reply_count' => $replyCount,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }
}

// <?php

// namespace App\Http\Controllers\Company;

// use App\Http\Controllers\Controller;
// use Illuminate\Http\Request;
// use App\Models\CompanyTicket;
// use App\Models\CompanyDriver;
// use App\Models\CompanyUser;
// use Illuminate\Support\Facades\Mail;
// use App\Models\CompanySetting;
// use App\Models\Setting;

// class TicketController extends Controller
// {
//     public function listTicket(Request $request){
//         try{
//             $query = CompanyTicket::orderBy("id", "DESC");

//             if(isset($request->status) && $request->status != NULL){
//                 $query->where("status", $request->status);
//             }

//             if(isset($request->search) && $request->search != NULL){
//                 $query->where(function($q) use ($request){
//                     $q->where("subject", "LIKE", "%". $request->search ."%")
//                         ->orWHere("message", "LIKE", "%". $request->serach ."%");
//                 });
//             }
//             $list = $query->paginate(10);

//             return response()->json([
//                 'success' => 1,
//                 'list' => $list
//             ]);
//         }
//         catch(\Exception $e){
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessae()
//             ]);
//         }
//     }

//     public function changeTicketStatus(Request $request){
//         try{
//             $request->validate([
//                 'ticket_id' => 'required',
//                 'status' => 'required'
//             ]);

//             $ticket = CompanyTicket::where("id", $request->ticket_id)->first();
//             $ticket->status = $request->status;
//             $ticket->save();

//             if($ticket->user_type == "driver"){
//                 $user = CompanyDriver::where("id", $ticket->user_id)->first();
//             }
//             else{
//                 $user = CompanyUser::where("id", $ticket->user_id)->first();
//             }

//             $settingData = CompanySetting::orderBy("id", "DESC")->first();
//             if($settingData->map_settings == "default"){
            
//                 $centralData = (new Setting)
//                     ->setConnection('central')
//                     ->orderBy("id", "DESC")
//                     ->first();
                    
//                 $mail_server = $centralData->smtp_host;
//                 $mail_from = $centralData->smtp_from_address;
//                 $mail_user_name = $centralData->smtp_user_name;
//                 $mail_password = $centralData->smtp_password;
//                 $mail_port = 587;
//             }
//             else{
//                 $mail_server = $settingData->mail_server;
//                 $mail_from = $settingData->mail_from;
//                 $mail_user_name = $settingData->mail_user_name;
//                 $mail_password = $settingData->mail_password;
//                 $mail_port = $settingData->mail_port;
//             }

//             config([
//                 'mail.mailers.smtp.host' => $mail_server,
//                 'mail.mailers.smtp.port' => $mail_port,
//                 'mail.mailers.smtp.username' => $mail_user_name,
//                 'mail.mailers.smtp.password' => $mail_password,
//                 'mail.from.address' => $mail_from,
//                 'mail.from.name' => $mail_user_name,
//             ]);

//             Mail::send('emails.ticket-close', [
//                 'name' => $user->name ?? 'User',
//                 'ticket_id' => $ticket->ticket_id,
//                 'subject' => $ticket->subject,
//             ], function ($message) use ($user) {
//                 $message->to($user->email)
//                         ->subject('Wallet Topup');
//             });

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Ticket status updated successfully'
//             ]);
//         }
//         catch(\Exception $e){
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function replyTicket(Request $request){
//         try{
//             $request->validate([
//                 'ticket_id' => 'required',
//                 'reply_message' => 'required',
//             ]);

//             $ticket = CompanyTicket::where("id", $request->ticket_id)->first();
//             $ticket->reply_message = $request->reply_message;
//             $ticket->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => "Messsage updated successfully"
//             ]);
//         }
//         catch(\Exception $e){
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }
// }
