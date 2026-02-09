<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyTicket;
use App\Models\CompanyDriver;
use App\Models\CompanyUser;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanySetting;
use App\Models\Setting;

class TicketController extends Controller
{
    public function listTicket(Request $request){
        try{
            $query = CompanyTicket::orderBy("id", "DESC");

            if(isset($request->status) && $request->status != NULL){
                $query->where("status", $request->status);
            }

            if(isset($request->search) && $request->search != NULL){
                $query->where(function($q) use ($request){
                    $q->where("subject", "LIKE", "%". $request->search ."%")
                        ->orWHere("message", "LIKE", "%". $request->serach ."%");
                });
            }
            $list = $query->paginate(10);

            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessae()
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
            $ticket->status = $request->status;
            $ticket->save();

            if($ticket->user_type == "driver"){
                $user = CompanyDriver::where("id", $ticket->user_id)->first();
            }
            else{
                $user = CompanyUser::where("id", $ticket->user_id)->first();
            }

            $settingData = CompanySetting::orderBy("id", "DESC")->first();
            if($settingData->map_settings == "default"){
            
                $centralData = (new Setting)
                    ->setConnection('central')
                    ->orderBy("id", "DESC")
                    ->first();
                    
                $mail_server = $centralData->smtp_host;
                $mail_from = $centralData->smtp_from_address;
                $mail_user_name = $centralData->smtp_user_name;
                $mail_password = $centralData->smtp_password;
                $mail_port = 587;
            }
            else{
                $mail_server = $settingData->mail_server;
                $mail_from = $settingData->mail_from;
                $mail_user_name = $settingData->mail_user_name;
                $mail_password = $settingData->mail_password;
                $mail_port = $settingData->mail_port;
            }

            config([
                'mail.mailers.smtp.host' => $mail_server,
                'mail.mailers.smtp.port' => $mail_port,
                'mail.mailers.smtp.username' => $mail_user_name,
                'mail.mailers.smtp.password' => $mail_password,
                'mail.from.address' => $mail_from,
                'mail.from.name' => $mail_user_name,
            ]);

            Mail::send('emails.ticket-close', [
                'name' => $user->name ?? 'User',
                'ticket_id' => $ticket->ticket_id,
                'subject' => $ticket->subject,
            ], function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Wallet Topup');
            });

            return response()->json([
                'success' => 1,
                'message' => 'Ticket status updated successfully'
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
            $ticket->reply_message = $request->reply_message;
            $ticket->save();

            return response()->json([
                'success' => 1,
                'message' => "Messsage updated successfully"
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
