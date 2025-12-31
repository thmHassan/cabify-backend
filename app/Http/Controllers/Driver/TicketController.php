<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyTicket;

class TicketController extends Controller
{
    public function createTicket(Request $request){
        try{
            $request->validate([
                'subject' => 'required',
                'message' => 'required'
            ]);

            $ticket = new CompanyTicket;
            $ticket->user_id = auth('driver')->user()->id;
            $ticket->user_type = "driver";
            $ticket->subject = $request->subject;
            $ticket->message = $request->message;
            $ticket->save();
            $ticket->ticket_id = "TC". str_pad($ticket->id, 6, '0', STR_PAD_LEFT);
            $ticket->save();

            return response()->json([
                'success' => 1,
                'message' => 'Ticket submitted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function ticketList(Request $request){
        try{
            $list = CompanyTicket::where('user_id', auth('driver')->user()->id)->orderBy("id", "DESC")->paginate(10);

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
