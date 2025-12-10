<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyTicket;

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
