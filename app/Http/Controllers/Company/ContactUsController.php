<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\CompanyContactUs;
use Illuminate\Http\Request;

class ContactUsController extends Controller
{
    public function listContactUs(Request $request)
    {
        try {
            $perPage = $request->input('perPage', $request->input('limit', 10));

            $query = CompanyContactUs::query()->orderByDesc('id');

            if ($request->filled('type') && in_array($request->type, ['user', 'driver', 'company'], true)) {
                $query->where('user_type', $request->type);
            }

            if ($request->filled('status') && in_array($request->status, ['pending', 'responded'], true)) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('message', 'LIKE', "%{$search}%")
                        ->orWhere('response', 'LIKE', "%{$search}%")
                        ->orWhere('user_id', 'LIKE', "%{$search}%");
                });
            }

            $list = $query->paginate($perPage);

            return response()->json([
                'success' => 1,
                'list' => $list,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getContactUs(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);

            $contact = CompanyContactUs::where('id', $request->id)->first();

            if (!$contact) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Contact request not found',
                ], 404);
            }

            return response()->json([
                'success' => 1,
                'data' => $contact,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function createContactUs(Request $request)
    {
        try {
            $request->validate([
                'message' => 'required|string',
            ]);

            $contact = new CompanyContactUs;
            $contact->user_type = 'company';
            $contact->user_id = auth('tenant')->user()->id;
            $contact->message = $request->message;
            $contact->status = 'pending';
            $contact->save();

            return response()->json([
                'success' => 1,
                'message' => 'Your message has been sent successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function respondContactUs(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'response' => 'required|string',
            ]);

            $contact = CompanyContactUs::where('id', $request->id)->first();

            if (!$contact) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Contact request not found',
                ], 404);
            }

            $contact->response = $request->response;
            $contact->status = 'responded';
            $contact->responded_at = now();
            $contact->save();

            return response()->json([
                'success' => 1,
                'message' => 'Response sent successfully',
                'data' => $contact,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
