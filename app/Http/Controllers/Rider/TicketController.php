<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyTicket;
use App\Models\CompanyTicketReply;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class TicketController extends Controller
{
    private function notifyTicketCreatedSocket(Request $request, CompanyTicket $ticket): void
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

        $rider = auth('rider')->user();
        $ticketPayload = $ticket->toArray();
        $ticketPayload['customer'] = $rider?->name ?? 'Customer';
        $ticketPayload['user_type'] = 'user';
        $ticketPayload['user_detail'] = [
            'id' => $rider?->id,
            'user_id' => $rider?->id,
            'user_type' => 'user',
            'name' => $rider?->name,
            'email' => $rider?->email,
            'country_code' => $rider?->country_code,
            'phone_no' => $rider?->phone_no ?? $rider?->phone ?? null,
            'phone' => $rider?->phone ?? $rider?->phone_no ?? null,
        ];
        $ticketPayload['customer_detail'] = $ticketPayload['user_detail'];
        $ticketPayload['replies'] = [];
        $ticketPayload['reply_count'] = 0;

        try {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . $socketSecret,
                'database' => $database,
            ])->timeout(5)->post($socketUrl . '/ticket-created', [
                'database' => $database,
                'ticket' => $ticketPayload,
            ]);
        } catch (\Throwable $socketException) {
            \Log::warning('Ticket created socket delivery failed', [
                'ticket_id' => $ticket->id,
                'error' => $socketException->getMessage(),
            ]);
        }
    }

    private function storeTicketImage(Request $request): ?string
    {
        $file = $request->file('image') ?: $request->file('ticket_image');
        if (!$file) {
            return null;
        }

        $folder = 'ticket_images';
        File::ensureDirectoryExists(public_path($folder));

        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move(public_path($folder), $filename);

        return $folder . '/' . $filename;
    }

    private function attachReplies($list)
    {
        $hasRepliesTable = Schema::hasTable('ticket_replies');

        $list->getCollection()->transform(function ($ticket) use ($hasRepliesTable) {
            $replies = collect();

            if ($hasRepliesTable) {
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

            $ticket->replies = $replies->values();
            $ticket->reply_count = $replies->count();

            return $ticket;
        });

        return $list;
    }

    public function createTicket(Request $request){
        try{
            $request->validate([
                'subject' => 'required',
                'message' => 'required',
                'image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
                'ticket_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
            ]);

            $openTicket = CompanyTicket::where('user_id', auth('rider')->user()->id)
                ->whereIn('user_type', ['user', 'rider'])
                ->where('status', 'open')
                ->orderBy('id', 'DESC')
                ->first();

            if ($openTicket) {
                return response()->json([
                    'error' => 1,
                    'message' => 'You already have an open ticket. Please wait until it is closed before creating a new ticket.',
                    'ticket' => $openTicket,
                ], 409);
            }

            $ticket = new CompanyTicket;
            $ticket->user_id = auth('rider')->user()->id;
            $ticket->user_type = "user";
            $ticket->subject = $request->subject;
            $ticket->message = $request->message;
            $ticket->image = $this->storeTicketImage($request);
            $ticket->save();
            $ticket->ticket_id = "TC". str_pad($ticket->id, 6, '0', STR_PAD_LEFT);
            $ticket->save();
            $ticket->refresh();

            $this->notifyTicketCreatedSocket($request, $ticket);

            return response()->json([
                'success' => 1,
                'message' => 'Ticket submitted successfully',
                'ticket' => $ticket,
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function listTicket(Request $request){
        try{
            $list = CompanyTicket::where('user_id', auth('rider')->user()->id)->orderBy("id", "DESC")->paginate(10);
            $this->attachReplies($list);

            return response()->json([
                'success' => 1,
                'message' => 'Ticket list fetched successfully',
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
}
