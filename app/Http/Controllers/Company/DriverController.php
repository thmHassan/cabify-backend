<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use Illuminate\Validation\Rule;
use App\Models\DriverDocument;
use App\Models\CompanyBooking;
use App\Models\CompanyNotification;
use App\Models\CompanySetting;
use App\Models\Setting;
use App\Services\FCMService;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanyToken;
use App\Models\CompanyVehicleType;
use App\Services\DriverSessionService;
use Hash;

class DriverController extends Controller
{
    public function createDriver(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|max:255',
                'email' => 'required|email|unique:drivers,email',
                'password' => 'required|string|min:6',
                'phone_no' => [
                    'required',
                    'max:255',
                    Rule::unique('drivers')->where(function ($query) use ($request) {
                        return $query->where('country_code', $request->country_code);
                    }),
                ],
                'address' => 'required|max:255',
                'driver_license' => 'required|max:255',
                'joined_date' => 'nullable|date'
            ]);

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where("id", $request->header('database'))
                ->first();

            $countDriver = CompanyDriver::count();

            if ($countDriver >= $dataCheck->data['drivers_allowed']) {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->post(env('NODE_SOCKET_URL') . '/send-reminder', [
                            'clientId' => $request->header('database'),
                            'title' => "Driver Limit",
                            'description' => "You have reached your driver limits"
                        ]);

                return response()->json([
                    'error' => 1, 
                    'message' => 'You have already reached to driver limits'
                ]);
            }

            $vehicleDetail = CompanyVehicleType::where("id", $request->assigned_vehicle)->first();

            $driver = new CompanyDriver;
            $driver->name = $request->name;
            $driver->email = $request->email;
            $driver->password = Hash::make($request->password);
            $driver->country_code = $request->country_code;
            $driver->phone_no = $request->phone_no;
            $driver->address = $request->address;
            $driver->driver_license = $request->driver_license;
            $driver->assigned_vehicle = $request->assigned_vehicle;
            $driver->vehicle_name = isset($vehicleDetail->vehicle_type_name) ? $vehicleDetail->vehicle_type_name : NULL;
            $driver->vehicle_type = isset($vehicleDetail->vehicle_type_service) ? $vehicleDetail->vehicle_type_service : NULL;
            $driver->vehicle_service = isset($vehicleDetail->vehicle_type_service) ? $vehicleDetail->vehicle_type_service : NULL;
            $driver->status = "accepted";
            $driver->joined_date = $request->filled('joined_date') ? $request->joined_date : now()->toDateString();
            $driver->sub_company = $request->sub_company;
            $driver->package_id = $request->package_id;
            $driver->dispatcher_id = $request->dispatcher_id;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver saved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function editDriver(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'name' => 'required|max:255',
                'email' => [
                    'required',
                    'email',
                    Rule::unique('drivers')->ignore($request->id),
                ],
                'phone_no' => [
                    'required',
                    'max:255',
                    Rule::unique('drivers', 'phone_no')
                        ->where(fn ($q) => $q->where('country_code', $request->country_code))
                        ->ignore($request->id),
                ],
                'address' => 'nullable|max:255',
                'driver_license' => 'nullable|max:255',
                'joined_date' => 'nullable',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
            ]);
            
            $driver = CompanyDriver::where("id", $request->id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            if ($request->hasFile('profile_image')) {
                $hasExistingImage = !empty($driver->profile_image);
                $canUpdateImage = !$hasExistingImage
                    || $driver->profile_image_approval_status === 'approved';

                if (!$canUpdateImage) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Profile image update requires admin approval first.',
                    ], 422);
                }

                $driver->profile_image = $this->storeDriverProfileImage($request->file('profile_image'));

                if ($hasExistingImage && $driver->profile_image_approval_status === 'approved') {
                    $driver->profile_image_approval_status = null;
                    $driver->profile_image_approval_description = null;
                    $driver->profile_image_pending = null;
                }
            }

            $driver->name = isset($request->name) ? $request->name : $driver->name;
            $driver->email = isset($request->email) ? $request->email : $driver->email;
            $driver->country_code = isset($request->country_code) ? $request->country_code : $driver->country_code; 
            $driver->password = $request->filled('password') ? Hash::make($request->password) : $driver->password;
            $driver->phone_no = isset($request->phone_no) ? $request->phone_no : $driver->phone_no; 
            $driver->address = $request->filled('address') ? $request->address : $driver->address;
            $driver->driver_license = $request->filled('driver_license') ? $request->driver_license : $driver->driver_license;
            $assignedVehicleId = $request->filled('assigned_vehicle') ? $request->assigned_vehicle : null;
            if (!$assignedVehicleId && $request->filled('vehicle_type') && is_numeric($request->vehicle_type)) {
                $assignedVehicleId = $request->vehicle_type;
            }

            if ($assignedVehicleId) {
                $vehicleDetail = CompanyVehicleType::where("id", $assignedVehicleId)->first();
                if ($vehicleDetail) {
                    $driver->assigned_vehicle = $assignedVehicleId;
                    $driver->vehicle_name = $request->filled('vehicle_name') ? $request->vehicle_name : $vehicleDetail->vehicle_type_name;
                    $driver->vehicle_type = $vehicleDetail->vehicle_type_service;
                    $driver->vehicle_service = $vehicleDetail->vehicle_type_service;
                }
            } else {
                $driver->vehicle_name = $request->filled('vehicle_name') ? $request->vehicle_name : $driver->vehicle_name;
                $driver->vehicle_type = $request->filled('vehicle_type') ? $request->vehicle_type : $driver->vehicle_type;
                $driver->vehicle_service = $request->filled('vehicle_service') ? $request->vehicle_service : $driver->vehicle_service;
            }

            $driver->seats = $request->filled('seats') ? $request->seats : $driver->seats;
            $driver->color = $request->filled('color') ? $request->color : $driver->color;
            $driver->plate_no = $request->filled('plate_no') ? $request->plate_no : $driver->plate_no;
            $driver->vehicle_registration_date = $request->filled('vehicle_registration_date') ? $request->vehicle_registration_date : $driver->vehicle_registration_date;

            $driver->joined_date = $request->filled('joined_date') ? $request->joined_date : $driver->joined_date;
            $driver->sub_company = $request->filled('sub_company') ? $request->sub_company : $driver->sub_company;
            $driver->package_id = $request->filled('package_id') ? $request->package_id : $driver->package_id;
            $driver->bank_name = isset($request->bank_name) ? $request->bank_name : $driver->bank_name;
            $driver->bank_account_number = isset($request->bank_account_number) ? $request->bank_account_number : $driver->bank_account_number;
            $driver->account_holder_name = isset($request->account_holder_name) ? $request->account_holder_name : $driver->account_holder_name;
            $driver->bank_phone_no = isset($request->bank_phone_no) ? $request->bank_phone_no : $driver->bank_phone_no;
            $driver->iban_no = isset($request->iban_no) ? $request->iban_no : $driver->iban_no;
            $driver->dispatcher_id = $request->filled('dispatcher_id') ? $request->dispatcher_id : $driver->dispatcher_id;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getEditDriver(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where('id', $request->id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            return response()->json([
                'success' => 1,
                'driver' => $driver,
                'profile_image' => $driver->profile_image,
                'profile_image_url' => $driver->profile_image_url,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function driverProfileImageApprovalStatus(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where('id', $request->id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            return response()->json($this->buildProfileImageApprovalResponse($driver));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitDriverProfileImageApproval(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'description' => 'nullable|string|max:255',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png,gif,webp|max:5120',
            ]);

            $driver = CompanyDriver::where('id', $request->id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            if (!$driver->profile_image) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Upload a profile image from driver edit first.',
                ], 422);
            }

            if ($driver->profile_image_approval_status === 'pending') {
                return response()->json([
                    'error' => 1,
                    'message' => 'A profile image update request is already pending.',
                ], 422);
            }

            $driver->profile_image_approval_status = 'pending';
            $driver->profile_image_approval_description = $request->description;

            if ($request->hasFile('profile_image')) {
                $driver->profile_image_pending = $this->storeDriverProfileImage($request->file('profile_image'));
            }

            $driver->save();

            return response()->json(array_merge(
                $this->buildProfileImageApprovalResponse($driver),
                ['message' => 'Profile image update request submitted for approval.']
            ));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildProfileImageApprovalResponse(CompanyDriver $driver): array
    {
        $approvalStatus = $driver->profile_image_approval_status ?? '';
        $hasProfileImage = !empty($driver->profile_image);

        $canUpdate = (!$hasProfileImage || $approvalStatus === 'approved') ? 1 : 0;

        return [
            'success' => 1,
            'message' => '',
            'approval_status' => $approvalStatus,
            'can_update' => $canUpdate,
            'description' => $driver->profile_image_approval_description ?? '',
        ];
    }

    private function storeDriverProfileImage($file): string
    {
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $destination = public_path('profile_image');

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $file->move($destination, $filename);

        return 'profile_image/' . $filename;
    }

    public function driverProfileImageApprovalList(Request $request)
    {
        try {
            $perPage = $request->input('perPage', 10);

            $query = CompanyDriver::query()
                ->whereNotNull('profile_image_approval_status')
                ->where('profile_image_approval_status', '!=', '')
                ->orderByDesc('updated_at');

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('profile_image_approval_description', 'LIKE', "%{$search}%");
                });
            }

            $paginator = $query->paginate($perPage);

            $paginator->getCollection()->transform(function (CompanyDriver $driver) {
                return [
                    'id' => $driver->id,
                    'driver_id' => $driver->id,
                    'driver_name' => $driver->name,
                    'driver_email' => $driver->email,
                    'profile_image' => $driver->profile_image_pending ?: $driver->profile_image,
                    'description' => $driver->profile_image_approval_description ?? '',
                    'approval_status' => $driver->profile_image_approval_status,
                    'created_at' => $driver->updated_at
                        ? $driver->updated_at->format('Y-m-d H:i:s')
                        : null,
                ];
            });

            return response()->json([
                'success' => 1,
                'list' => $paginator,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function approveDriverProfileImageApproval(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where('id', $request->id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            if ($driver->profile_image_approval_status !== 'pending') {
                return response()->json([
                    'error' => 1,
                    'message' => 'Only pending profile image requests can be approved.',
                ], 422);
            }

            if ($driver->profile_image_pending) {
                $driver->profile_image = $driver->profile_image_pending;
                $driver->profile_image_pending = null;
            }

            $driver->profile_image_approval_status = 'approved';
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Profile image update approved',
                'approval_status' => 'approved',
                'can_update' => 1,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function rejectDriverProfileImageApproval(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where('id', $request->id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            if ($driver->profile_image_approval_status !== 'pending') {
                return response()->json([
                    'error' => 1,
                    'message' => 'Only pending profile image requests can be rejected.',
                ], 422);
            }

            $driver->profile_image_pending = null;
            $driver->profile_image_approval_status = 'rejected';
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Profile image update rejected',
                'approval_status' => 'rejected',
                'can_update' => 0,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function listDriver(Request $request)
    {
        try {
            $perPage = 10;
            if (isset($request->perPage) && $request->perPage != NULL) {
                $perPage = $request->perPage;
            }
            $drivers = CompanyDriver::orderBy("id", "DESC");
            if (isset($request->status) && $request->status == "pending") {
                $drivers->where(function ($query) use ($request) {
                    $query->where("status", $request->status)->orWhereNULL("status");
                });
            } elseif (isset($request->status)) {
                $drivers->where("status", $request->status);
            } elseif (isset($request->sub_company)) {
                $drivers->where("sub_company", $request->sub_company);
            }
            if (isset($request->search) && $request->search != NULL) {
                $drivers->where(function ($query) use ($request) {
                    $query->where("name", "LIKE", "%" . $request->search . "%")
                        ->orWhere("email", "LIKE", "%" . $request->search . "%");
                });
            }
            // if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
            //     $query->where("dispatcher_id", $request->dispatcher_id);
            // }
            $list = $drivers->paginate($perPage);
            return response()->json([
                'success' => 1,
                'list' => $list
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDriver(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            if (isset($driver) && $driver != NULL) {
                $driver->delete();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Driver deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeDriverStatus(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'status' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            $driver->status = $request->status;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function addWalletBalance(Request $request)
    {
        try {
            $request->validate([
                'id' => 'required',
                'amount' => 'required',
            ]);

            $driver = CompanyDriver::where("id", $request->id)->first();
            $driver->wallet_balance += $request->amount;
            $driver->save();

            $settingData = CompanySetting::orderBy("id", "DESC")->first();

            // if(isset($driver->email) && $driver->email != NULL){
            //     if ($settingData->map_settings == "default") {
    
            //         $centralData = (new Setting)
            //             ->setConnection('central')
            //             ->orderBy("id", "DESC")
            //             ->first();
    
            //         $mail_server = $centralData->smtp_host;
            //         $mail_from = $centralData->smtp_from_address;
            //         $mail_user_name = $centralData->smtp_user_name;
            //         $mail_password = $centralData->smtp_password;
            //         $mail_port = 587;
            //     } else {
            //         $mail_server = $settingData->mail_server;
            //         $mail_from = $settingData->mail_from;
            //         $mail_user_name = $settingData->mail_user_name;
            //         $mail_password = $settingData->mail_password;
            //         $mail_port = $settingData->mail_port;
            //     }
    
            //     config([
            //         'mail.mailers.smtp.host' => $mail_server,
            //         'mail.mailers.smtp.port' => $mail_port,
            //         'mail.mailers.smtp.username' => $mail_user_name,
            //         'mail.mailers.smtp.password' => $mail_password,
            //         'mail.from.address' => $mail_from,
            //         'mail.from.name' => $mail_user_name,
            //     ]);
    
            //     Mail::send('emails.wallet-topup', [
            //         'name' => $driver->name ?? 'User',
            //         'amount' => $request->amount,
            //     ], function ($message) use ($driver) {
            //         $message->to($driver->email)
            //             ->subject('Wallet Topup');
            //     });
            // }


            return response()->json([
                'success' => 1,
                'message' => 'Balance added successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function approvVehicleDetails(Request $request)
    {
        try {
            $request->validate([
                'driver_id' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->driver_id)->first();
            $driver->vehicle_change_request = "2";
            $driver->vehicle_service = $driver->change_vehicle_service;
            $driver->vehicle_type = $driver->change_vehicle_type;
            $driver->color = $driver->change_color;
            $driver->seats = $driver->change_seats;
            $driver->plate_no = $driver->change_plate_no;
            $driver->vehicle_registration_date = $driver->change_vehicle_registration_date;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle information approved successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function rejectVehicleDetails(Request $request)
    {
        try {
            $request->validate([
                'driver_id' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->driver_id)->first();
            $driver->vehicle_change_request = 3;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => 'Vehicle information rejected successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function driverDocumentList(Request $request)
    {
        try {
            $documentList = DriverDocument::where("driver_id", $request->driver_id)->with("documentDetail")->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'documentList' => $documentList
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changeStatusDocument(Request $request)
    {
        try {
            if (isset($request->approve_all) && $request->approve_all == 1) {
                $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
                foreach ($documentList as $document) {
                    $document->status = 'verified';
                    $document->save();
                }
                $user = CompanyDriver::where("id", $request->driver_id)->first();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                
                // if(isset($user->email) && $user->email != NULL){
                //     if ($settingData->map_settings == "default") {
    
                //         $centralData = (new Setting)
                //             ->setConnection('central')
                //             ->orderBy("id", "DESC")
                //             ->first();
    
                //         $mail_server = $centralData->smtp_host;
                //         $mail_from = $centralData->smtp_from_address;
                //         $mail_user_name = $centralData->smtp_user_name;
                //         $mail_password = $centralData->smtp_password;
                //         $mail_port = 587;
                //     } else {
                //         $mail_server = $settingData->mail_server;
                //         $mail_from = $settingData->mail_from;
                //         $mail_user_name = $settingData->mail_user_name;
                //         $mail_password = $settingData->mail_password;
                //         $mail_port = $settingData->mail_port;
                //     }
    
                //     config([
                //         'mail.mailers.smtp.host' => $mail_server,
                //         'mail.mailers.smtp.port' => $mail_port,
                //         'mail.mailers.smtp.username' => $mail_user_name,
                //         'mail.mailers.smtp.password' => $mail_password,
                //         'mail.from.address' => $mail_from,
                //         'mail.from.name' => $mail_user_name,
                //     ]);
    
                //     Mail::send('emails.document-status', [
                //         'name' => $user->name ?? 'User',
                //         'status' => "approved",
                //     ], function ($message) use ($user) {
                //         $message->to($user->email)
                //             ->subject('Document Status Updated');
                //     });
                // }

            } else if (isset($request->reject_all) && $request->reject_all == 1) {
                $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
                foreach ($documentList as $document) {
                    $document->status = 'failed';
                    $document->save();
                }

                $user = CompanyDriver::where("id", $request->driver_id)->first();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                // if(isset($user->email) && $user->email != NULL){
                //     if ($settingData->map_settings == "default") {
    
                //         $centralData = (new Setting)
                //             ->setConnection('central')
                //             ->orderBy("id", "DESC")
                //             ->first();
    
                //         $mail_server = $centralData->smtp_host;
                //         $mail_from = $centralData->smtp_from_address;
                //         $mail_user_name = $centralData->smtp_user_name;
                //         $mail_password = $centralData->smtp_password;
                //         $mail_port = 587;
                //     } else {
                //         $mail_server = $settingData->mail_server;
                //         $mail_from = $settingData->mail_from;
                //         $mail_user_name = $settingData->mail_user_name;
                //         $mail_password = $settingData->mail_password;
                //         $mail_port = $settingData->mail_port;
                //     }
    
                //     config([
                //         'mail.mailers.smtp.host' => $mail_server,
                //         'mail.mailers.smtp.port' => $mail_port,
                //         'mail.mailers.smtp.username' => $mail_user_name,
                //         'mail.mailers.smtp.password' => $mail_password,
                //         'mail.from.address' => $mail_from,
                //         'mail.from.name' => $mail_user_name,
                //     ]);
    
                //     Mail::send('emails.document-status', [
                //         'name' => $user->name ?? 'User',
                //         'status' => "rejected",
                //     ], function ($message) use ($user) {
                //         $message->to($user->email)
                //             ->subject('Document Status Updated');
                //     });
                // }
            } else if (isset($request->driver_document_id) && $request->driver_document_id != NULL) {
                $document = DriverDocument::where("id", $request->driver_document_id)->first();
                $document->status = $request->status;
                $document->save();

                $user = CompanyDriver::where("id", $request->driver_id)->first();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                // if(isset($user->email) && $user->email != NULL){
                //     if ($settingData->map_settings == "default") {
    
                //         $centralData = (new Setting)
                //             ->setConnection('central')
                //             ->orderBy("id", "DESC")
                //             ->first();
    
                //         $mail_server = $centralData->smtp_host;
                //         $mail_from = $centralData->smtp_from_address;
                //         $mail_user_name = $centralData->smtp_user_name;
                //         $mail_password = $centralData->smtp_password;
                //         $mail_port = 587;
                //     } else {
                //         $mail_server = $settingData->mail_server;
                //         $mail_from = $settingData->mail_from;
                //         $mail_user_name = $settingData->mail_user_name;
                //         $mail_password = $settingData->mail_password;
                //         $mail_port = $settingData->mail_port;
                //     }
    
                //     config([
                //         'mail.mailers.smtp.host' => $mail_server,
                //         'mail.mailers.smtp.port' => $mail_port,
                //         'mail.mailers.smtp.username' => $mail_user_name,
                //         'mail.mailers.smtp.password' => $mail_password,
                //         'mail.from.address' => $mail_from,
                //         'mail.from.name' => $mail_user_name,
                //     ]);
    
                //     Mail::send('emails.document-status', [
                //         'name' => $user->name ?? 'User',
                //         'status' => $request->status,
                //     ], function ($message) use ($user) {
                //         $message->to($user->email)
                //             ->subject('Document Status Updated');
                //     });
                // }
            } else if (isset($request->document_approved_office) && $request->document_approved_office == 1) {
                $driver = CompanyDriver::where("id", $request->driver_id)->first();
                $driver->document_approved_office = 1;
                $driver->save();

                $settingData = CompanySetting::orderBy("id", "DESC")->first();
                // if(isset($driver->email) && $driver->email != NULL){
                //     if ($settingData->map_settings == "default") {
    
                //         $centralData = (new Setting)
                //             ->setConnection('central')
                //             ->orderBy("id", "DESC")
                //             ->first();
    
                //         $mail_server = $centralData->smtp_host;
                //         $mail_from = $centralData->smtp_from_address;
                //         $mail_user_name = $centralData->smtp_user_name;
                //         $mail_password = $centralData->smtp_password;
                //         $mail_port = 587;
                //     } else {
                //         $mail_server = $settingData->mail_server;
                //         $mail_from = $settingData->mail_from;
                //         $mail_user_name = $settingData->mail_user_name;
                //         $mail_password = $settingData->mail_password;
                //         $mail_port = $settingData->mail_port;
                //     }
    
                //     config([
                //         'mail.mailers.smtp.host' => $mail_server,
                //         'mail.mailers.smtp.port' => $mail_port,
                //         'mail.mailers.smtp.username' => $mail_user_name,
                //         'mail.mailers.smtp.password' => $mail_password,
                //         'mail.from.address' => $mail_from,
                //         'mail.from.name' => $mail_user_name,
                //     ]);
    
                //     Mail::send('emails.document-status', [
                //         'name' => $driver->name ?? 'Driver',
                //         'status' => "approved",
                //     ], function ($message) use ($driver) {
                //         $message->to($driver->email)
                //             ->subject('Document Status Updated');
                //     });
                // }
            }
            return response()->json([
                'success' => 1,
                'message' => 'Document status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getDriverDocument(Request $request)
    {
        try {
            $document = DriverDocument::where("id", $request->id)->first();

            return response()->json([
                'success' => 1,
                'document' => $document
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function deleteDriverDocument(Request $request)
    {
        try {
            $document = DriverDocument::where("id", $request->id)->first();

            if (isset($document) && $document != NULL) {
                $document->delete();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Document deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function driverRideHistory(Request $request)
    {
        try {
            $rideHistory = CompanyBooking::where('driver', $request->driver_id)->orderBy("id", "DESC")->get();

            return response()->json([
                'success' => 1,
                'rideHistory' => $rideHistory
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function sendDriverMessage(Request $request)
    {
        try {
            $request->validate([
                'driver_id' => 'required|integer',
                'message' => 'required|string|max:1000',
                'ride_id' => 'nullable',
            ]);

            $driver = CompanyDriver::where('id', $request->driver_id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            if ($request->filled('ride_id')) {
                $booking = CompanyBooking::where('id', $request->ride_id)->first();
                if (!$booking) {
                    return response()->json([
                        'error' => 1,
                        'message' => 'Ride not found',
                    ], 404);
                }
            }

            $title = 'Message from Dispatch';
            $message = $request->message;

            $record = new CompanyNotification;
            $record->user_type = 'driver';
            $record->user_id = $driver->id;
            $record->title = $title;
            $record->message = $message;
            $record->status = 'unread';
            $record->save();

            $dataCheck = (new TenantUser)
                ->setConnection('central')
                ->where('id', $request->header('database'))
                ->first();

            $pushEnabled = !isset($dataCheck) || ($dataCheck->data['push_notification'] ?? 'enable') === 'enable';

            if ($pushEnabled) {
                $fcmData = [
                    'type' => 'driver_message',
                    'notification_id' => (string) $record->id,
                    'driver_id' => (string) $driver->id,
                ];

                if ($request->filled('ride_id')) {
                    $fcmData['ride_id'] = (string) $request->ride_id;
                }

                $tokens = CompanyToken::where('user_id', $request->driver_id)
                    ->where('user_type', 'driver')
                    ->get();

                foreach ($tokens as $token) {
                    FCMService::sendToDevice(
                        $token->fcm_token,
                        $title,
                        $message,
                        $fcmData
                    );
                }
            }

            $chatPayload = [
                'id' => $record->id,
                'send_by' => 'dispatcher',
                'driver_id' => (string) $request->driver_id,
                'message' => $message,
                'title' => $title,
                'status' => 'unread',
                'created_at' => $record->created_at?->toISOString() ?? now()->toISOString(),
            ];

            if ($request->filled('ride_id')) {
                $chatPayload['ride_id'] = (string) $request->ride_id;
            }

            try {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/driver-message-notification', [
                    'driverId' => $request->driver_id,
                    'chat' => $chatPayload,
                ]);
            } catch (\Exception $socketException) {
                \Log::warning('Driver message socket delivery failed', [
                    'driver_id' => $request->driver_id,
                    'notification_id' => $record->id,
                    'error' => $socketException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Message sent successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function logoutDriver(Request $request)
    {
        try {
            $request->validate([
                'driver_id' => 'required|integer',
            ]);

            $driver = CompanyDriver::where('id', $request->driver_id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            DriverSessionService::invalidate($driver);

            try {
                Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
                    'database' => $request->header('database'),
                ])->timeout(5)->post(env('NODE_SOCKET_URL') . '/driver-force-logout', [
                    'driverId' => $request->driver_id,
                    'auth_version' => $driver->auth_version,
                ]);
            } catch (\Exception $socketException) {
                \Log::warning('Driver force logout socket call failed', [
                    'driver_id' => $request->driver_id,
                    'error' => $socketException->getMessage(),
                ]);
            }

            return response()->json([
                'success' => 1,
                'message' => 'Driver logged out successfully',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function sendDriverNotification(Request $request)
    {
        try {
            $request->validate([
                'driver_id' => 'required',
                'title' => 'required',
                'body' => 'required'
            ]);

            $driver = CompanyDriver::where("id", $request->driver_id)->first();

            $tokens = CompanyToken::where("user_id", $request->driver_id)->where("user_type", "driver")->get();

            if (isset($tokens) && $tokens != NULL) {
                foreach ($tokens as $key => $token) {
                    FCMService::sendToDevice(
                        $token->fcm_token,
                        $request->title,
                        $request->body,
                    );
                }
            }

            $record = new CompanyNotification;
            $record->user_type = "driver";
            $record->user_id = $driver->id;
            $record->title = $request->title;
            $record->message = $request->body;
            $record->save();

            return response()->json([
                'success' => 1,
                'message' => 'Notification sent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function pendingDocumentList(Request $request)
    {
        try {
            $data = DriverDocument::where("status", "pending")->with(['driverDetail', 'documentDetail'])->paginate(10);

            return response()->json([
                'success' => 1,
                "data" => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getDriverEarnings(Request $request)
    {
        try {
            $request->validate([
                'driver_id' => 'required|exists:drivers,id',
            ]);

            // Get driver details
            $driver = CompanyDriver::where("id", $request->driver_id)->first();

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found'
                ]);
            }

            // Get all completed bookings for this driver
            $completedBookings = CompanyBooking::where('driver', $request->driver_id)
                ->where('booking_status', 'completed')
                ->get();

            // Calculate total earnings
            $totalEarnings = $completedBookings->sum('booking_amount');

            // Get count of completed rides
            $totalCompletedRides = $completedBookings->count();

            return response()->json([
                'success' => 1,
                'driver_name' => $driver->name,
                'driver_id' => $driver->id,
                'total_completed_rides' => $totalCompletedRides,
                'total_earnings' => number_format($totalEarnings, 2, '.', ''),
                'bookings' => $completedBookings
            ]);

        } catch (\Exception $e) {
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
// use App\Models\CompanyDriver;
// use Illuminate\Validation\Rule;
// use App\Models\DriverDocument;
// use App\Models\CompanyBooking;
// use App\Models\CompanyNotification;
// use App\Models\CompanySetting;
// use App\Models\Setting;
// use App\Services\FCMService;
// use App\Models\TenantUser;
// use Illuminate\Support\Facades\Http;
// use Illuminate\Support\Facades\Mail;
// use App\Models\CompanyToken;
// use App\Models\CompanyVehicleType;

// class DriverController extends Controller
// {
//     public function createDriver(Request $request)
//     {
//         try {
//             $request->validate([
//                 'name' => 'required|max:255',
//                 'email' => 'required|email|unique:drivers,email',
//                 'phone_no' => [
//                     'required',
//                     'max:255',
//                     Rule::unique('drivers')->where(function ($query) use ($request) {
//                         return $query->where('country_code', $request->country_code);
//                     }),
//                 ],
//                 'address' => 'required|max:255',
//                 'driver_license' => 'required|max:255',
//                 'joined_date' => 'required'
//             ]);

//             $dataCheck = (new TenantUser)
//                 ->setConnection('central')
//                 ->where("id", $request->header('database'))
//                 ->first();

//             $countDriver = CompanyDriver::count();

//             if ($countDriver >= $dataCheck->data['drivers_allowed']) {
//                 Http::withHeaders([
//                     'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
//                 ])->post(env('NODE_SOCKET_URL') . '/send-reminder', [
//                             'clientId' => $request->header('database'),
//                             'title' => "Driver Limit",
//                             'description' => "You have reached your driver limits"
//                         ]);

//                 return response()->json([
//                     'error' => 1,
//                     'message' => 'You have already reached to driver limits'
//                 ]);
//             }

//             $vehicleDetail = CompanyVehicleType::where("id", $request->assigned_vehicle)->first();

//             $driver = new CompanyDriver;
//             $driver->name = $request->name;
//             $driver->email = $request->email;
//             $driver->country_code = $request->country_code;
//             $driver->phone_no = $request->phone_no;
//             $driver->address = $request->address;
//             $driver->driver_license = $request->driver_license;
//             $driver->assigned_vehicle = $request->assigned_vehicle;
//             $driver->vehicle_name = isset($vehicleDetail->vehicle_type_name) ? $vehicleDetail->vehicle_type_name : NULL;
//             $driver->vehicle_type = isset($vehicleDetail->vehicle_type_service) ? $vehicleDetail->vehicle_type_service : NULL;
//             $driver->vehicle_service = isset($vehicleDetail->vehicle_type_service) ? $vehicleDetail->vehicle_type_service : NULL;
//             $driver->status = "pending";
//             $driver->joined_date = $request->joined_date;
//             $driver->sub_company = $request->sub_company;
//             $driver->package_id = $request->package_id;
//             $driver->dispatcher_id = $request->dispatcher_id;
//             $driver->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Driver saved successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function editDriver(Request $request)
//     {
//         try {
//             $request->validate([
//                 'id' => 'required',
//                 'name' => 'required|max:255',
//                 'email' => [
//                     'required',
//                     'email',
//                     Rule::unique('drivers')->ignore($request->id),
//                 ],
//                 'phone_no' => [
//                     'required',
//                     'max:255',
//                     Rule::unique('drivers', 'phone_no')
//                         ->where(fn ($q) => $q->where('country_code', $request->country_code))
//                         ->ignore($request->id),
//                 ],
//                 'address' => 'required|max:255',
//                 'driver_license' => 'required|max:255',
//                 'joined_date' => 'required',
//             ]);
            
//             $vehicleDetail = CompanyVehicleType::where("id", $request->assigned_vehicle)->first();

//             $driver = CompanyDriver::where("id", $request->id)->first();
//             $driver->name = isset($request->name) ? $request->name : $driver->name;
//             $driver->email = isset($request->email) ? $request->email : $driver->email;
//             $driver->country_code = isset($request->country_code) ? $request->country_code : $driver->country_code; 
//             $driver->phone_no = isset($request->phone_no) ? $request->phone_no : $driver->phone_no; 
//             $driver->address = isset($request->address) ? $request->address : $driver->address;
//             $driver->driver_license = isset($request->driver_license) ? $request->driver_license : $driver->driver_license;
//             $driver->assigned_vehicle = isset($request->assigned_vehicle) ? $request->assigned_vehicle : $driver->assigned_vehicle;
//             $driver->vehicle_name = isset($vehicleDetail->vehicle_type_name) ? $vehicleDetail->vehicle_type_name : NULL;
//             $driver->vehicle_type = isset($vehicleDetail->vehicle_type_service) ? $vehicleDetail->vehicle_type_service : NULL;
//             $driver->vehicle_service = isset($vehicleDetail->vehicle_type_service) ? $vehicleDetail->vehicle_type_service : NULL;
//             $driver->joined_date = isset($request->joined_date) ? $request->joined_date : $driver->joined_date;
//             $driver->sub_company = isset($request->sub_company) ? $request->sub_company : $driver->sub_company;
//             $driver->package_id = $request->package_id;
//             $driver->bank_name = isset($request->bank_name) ? $request->bank_name : $driver->bank_name;
//             $driver->bank_account_number = isset($request->bank_account_number) ? $request->bank_account_number : $driver->bank_account_number;
//             $driver->account_holder_name = isset($request->account_holder_name) ? $request->account_holder_name : $driver->account_holder_name;
//             $driver->bank_phone_no = isset($request->bank_phone_no) ? $request->bank_phone_no : $driver->bank_phone_no;
//             $driver->iban_no = isset($request->iban_no) ? $request->iban_no : $driver->iban_no;
//             $driver->dispatcher_id = $request->dispatcher_id;
//             $driver->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Driver updated successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function getEditDriver(Request $request)
//     {
//         try {
//             $request->validate([
//                 'id' => 'required',
//             ]);

//             $driver = CompanyDriver::where("id", $request->id)->first();
//             return response()->json([
//                 'success' => 1,
//                 'driver' => $driver
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function listDriver(Request $request)
//     {
//         try {
//             $perPage = 10;
//             if (isset($request->perPage) && $request->perPage != NULL) {
//                 $perPage = $request->perPage;
//             }
//             $drivers = CompanyDriver::orderBy("id", "DESC");
//             if (isset($request->status) && $request->status == "pending") {
//                 $drivers->where(function ($query) use ($request) {
//                     $query->where("status", $request->status)->orWhereNULL("status");
//                 });
//             } elseif (isset($request->status)) {
//                 $drivers->where("status", $request->status);
//             } elseif (isset($request->sub_company)) {
//                 $drivers->where("sub_company", $request->sub_company);
//             }
//             if (isset($request->search) && $request->search != NULL) {
//                 $drivers->where(function ($query) use ($request) {
//                     $query->where("name", "LIKE", "%" . $request->search . "%")
//                         ->orWhere("email", "LIKE", "%" . $request->search . "%");
//                 });
//             }
//             // if(isset($request->dispatcher_id) && $request->dispatcher_id != NULL){
//             //     $query->where("dispatcher_id", $request->dispatcher_id);
//             // }
//             $list = $drivers->paginate($perPage);
//             return response()->json([
//                 'success' => 1,
//                 'list' => $list
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function deleteDriver(Request $request)
//     {
//         try {
//             $request->validate([
//                 'id' => 'required',
//             ]);

//             $driver = CompanyDriver::where("id", $request->id)->first();
//             if (isset($driver) && $driver != NULL) {
//                 $driver->delete();
//             }
//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Driver deleted successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function changeDriverStatus(Request $request)
//     {
//         try {
//             $request->validate([
//                 'id' => 'required',
//                 'status' => 'required',
//             ]);

//             $driver = CompanyDriver::where("id", $request->id)->first();
//             $driver->status = $request->status;
//             $driver->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Driver status updated successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function addWalletBalance(Request $request)
//     {
//         try {
//             $request->validate([
//                 'id' => 'required',
//                 'amount' => 'required',
//             ]);

//             $driver = CompanyDriver::where("id", $request->id)->first();
//             $driver->wallet_balance += $request->amount;
//             $driver->save();

//             $settingData = CompanySetting::orderBy("id", "DESC")->first();
//             if ($settingData->map_settings == "default") {

//                 $centralData = (new Setting)
//                     ->setConnection('central')
//                     ->orderBy("id", "DESC")
//                     ->first();

//                 $mail_server = $centralData->smtp_host;
//                 $mail_from = $centralData->smtp_from_address;
//                 $mail_user_name = $centralData->smtp_user_name;
//                 $mail_password = $centralData->smtp_password;
//                 $mail_port = 587;
//             } else {
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

//             Mail::send('emails.wallet-topup', [
//                 'name' => $driver->name ?? 'User',
//                 'amount' => $request->amount,
//             ], function ($message) use ($driver) {
//                 $message->to($driver->email)
//                     ->subject('Wallet Topup');
//             });

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Balance added successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function approvVehicleDetails(Request $request)
//     {
//         try {
//             $request->validate([
//                 'driver_id' => 'required'
//             ]);

//             $driver = CompanyDriver::where("id", $request->driver_id)->first();
//             $driver->vehicle_change_request = "2";
//             $driver->vehicle_service = $driver->change_vehicle_service;
//             $driver->vehicle_type = $driver->change_vehicle_type;
//             $driver->color = $driver->change_color;
//             $driver->seats = $driver->change_seats;
//             $driver->plate_no = $driver->change_plate_no;
//             $driver->vehicle_registration_date = $driver->change_vehicle_registration_date;
//             $driver->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Vehicle information approved successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function rejectVehicleDetails(Request $request)
//     {
//         try {
//             $request->validate([
//                 'driver_id' => 'required'
//             ]);

//             $driver = CompanyDriver::where("id", $request->driver_id)->first();
//             $driver->vehicle_change_request = 3;
//             $driver->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Vehicle information rejected successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function driverDocumentList(Request $request)
//     {
//         try {
//             $documentList = DriverDocument::where("driver_id", $request->driver_id)->with("documentDetail")->orderBy("id", "DESC")->get();

//             return response()->json([
//                 'success' => 1,
//                 'documentList' => $documentList
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function changeStatusDocument(Request $request)
//     {
//         try {
//             if (isset($request->approve_all) && $request->approve_all == 1) {
//                 $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
//                 foreach ($documentList as $document) {
//                     $document->status = 'verified';
//                     $document->save();
//                 }
//                 $user = CompanyDriver::where("id", $request->driver_id)->first();

//                 $settingData = CompanySetting::orderBy("id", "DESC")->first();
//                 if ($settingData->map_settings == "default") {

//                     $centralData = (new Setting)
//                         ->setConnection('central')
//                         ->orderBy("id", "DESC")
//                         ->first();

//                     $mail_server = $centralData->smtp_host;
//                     $mail_from = $centralData->smtp_from_address;
//                     $mail_user_name = $centralData->smtp_user_name;
//                     $mail_password = $centralData->smtp_password;
//                     $mail_port = 587;
//                 } else {
//                     $mail_server = $settingData->mail_server;
//                     $mail_from = $settingData->mail_from;
//                     $mail_user_name = $settingData->mail_user_name;
//                     $mail_password = $settingData->mail_password;
//                     $mail_port = $settingData->mail_port;
//                 }

//                 config([
//                     'mail.mailers.smtp.host' => $mail_server,
//                     'mail.mailers.smtp.port' => $mail_port,
//                     'mail.mailers.smtp.username' => $mail_user_name,
//                     'mail.mailers.smtp.password' => $mail_password,
//                     'mail.from.address' => $mail_from,
//                     'mail.from.name' => $mail_user_name,
//                 ]);

//                 Mail::send('emails.document-status', [
//                     'name' => $user->name ?? 'User',
//                     'status' => "approved",
//                 ], function ($message) use ($user) {
//                     $message->to($user->email)
//                         ->subject('Document Status Updated');
//                 });

//             } else if (isset($request->reject_all) && $request->reject_all == 1) {
//                 $documentList = DriverDocument::where("driver_id", $request->driver_id)->where("status", "pending")->get();
//                 foreach ($documentList as $document) {
//                     $document->status = 'failed';
//                     $document->save();
//                 }

//                 $user = CompanyDriver::where("id", $request->driver_id)->first();

//                 $settingData = CompanySetting::orderBy("id", "DESC")->first();
//                 if ($settingData->map_settings == "default") {

//                     $centralData = (new Setting)
//                         ->setConnection('central')
//                         ->orderBy("id", "DESC")
//                         ->first();

//                     $mail_server = $centralData->smtp_host;
//                     $mail_from = $centralData->smtp_from_address;
//                     $mail_user_name = $centralData->smtp_user_name;
//                     $mail_password = $centralData->smtp_password;
//                     $mail_port = 587;
//                 } else {
//                     $mail_server = $settingData->mail_server;
//                     $mail_from = $settingData->mail_from;
//                     $mail_user_name = $settingData->mail_user_name;
//                     $mail_password = $settingData->mail_password;
//                     $mail_port = $settingData->mail_port;
//                 }

//                 config([
//                     'mail.mailers.smtp.host' => $mail_server,
//                     'mail.mailers.smtp.port' => $mail_port,
//                     'mail.mailers.smtp.username' => $mail_user_name,
//                     'mail.mailers.smtp.password' => $mail_password,
//                     'mail.from.address' => $mail_from,
//                     'mail.from.name' => $mail_user_name,
//                 ]);

//                 Mail::send('emails.document-status', [
//                     'name' => $user->name ?? 'User',
//                     'status' => "rejected",
//                 ], function ($message) use ($user) {
//                     $message->to($user->email)
//                         ->subject('Document Status Updated');
//                 });
//             } else if (isset($request->driver_document_id) && $request->driver_document_id != NULL) {
//                 $document = DriverDocument::where("id", $request->driver_document_id)->first();
//                 $document->status = $request->status;
//                 $document->save();

//                 $user = CompanyDriver::where("id", $request->driver_id)->first();

//                 $settingData = CompanySetting::orderBy("id", "DESC")->first();
//                 if ($settingData->map_settings == "default") {

//                     $centralData = (new Setting)
//                         ->setConnection('central')
//                         ->orderBy("id", "DESC")
//                         ->first();

//                     $mail_server = $centralData->smtp_host;
//                     $mail_from = $centralData->smtp_from_address;
//                     $mail_user_name = $centralData->smtp_user_name;
//                     $mail_password = $centralData->smtp_password;
//                     $mail_port = 587;
//                 } else {
//                     $mail_server = $settingData->mail_server;
//                     $mail_from = $settingData->mail_from;
//                     $mail_user_name = $settingData->mail_user_name;
//                     $mail_password = $settingData->mail_password;
//                     $mail_port = $settingData->mail_port;
//                 }

//                 config([
//                     'mail.mailers.smtp.host' => $mail_server,
//                     'mail.mailers.smtp.port' => $mail_port,
//                     'mail.mailers.smtp.username' => $mail_user_name,
//                     'mail.mailers.smtp.password' => $mail_password,
//                     'mail.from.address' => $mail_from,
//                     'mail.from.name' => $mail_user_name,
//                 ]);

//                 Mail::send('emails.document-status', [
//                     'name' => $user->name ?? 'User',
//                     'status' => $request->status,
//                 ], function ($message) use ($user) {
//                     $message->to($user->email)
//                         ->subject('Document Status Updated');
//                 });
//             } else if (isset($request->document_approved_office) && $request->document_approved_office == 1) {
//                 $driver = CompanyDriver::where("id", $request->driver_id)->first();
//                 $driver->document_approved_office = 1;
//                 $driver->save();

//                 $settingData = CompanySetting::orderBy("id", "DESC")->first();
//                 if ($settingData->map_settings == "default") {

//                     $centralData = (new Setting)
//                         ->setConnection('central')
//                         ->orderBy("id", "DESC")
//                         ->first();

//                     $mail_server = $centralData->smtp_host;
//                     $mail_from = $centralData->smtp_from_address;
//                     $mail_user_name = $centralData->smtp_user_name;
//                     $mail_password = $centralData->smtp_password;
//                     $mail_port = 587;
//                 } else {
//                     $mail_server = $settingData->mail_server;
//                     $mail_from = $settingData->mail_from;
//                     $mail_user_name = $settingData->mail_user_name;
//                     $mail_password = $settingData->mail_password;
//                     $mail_port = $settingData->mail_port;
//                 }

//                 config([
//                     'mail.mailers.smtp.host' => $mail_server,
//                     'mail.mailers.smtp.port' => $mail_port,
//                     'mail.mailers.smtp.username' => $mail_user_name,
//                     'mail.mailers.smtp.password' => $mail_password,
//                     'mail.from.address' => $mail_from,
//                     'mail.from.name' => $mail_user_name,
//                 ]);

//                 Mail::send('emails.document-status', [
//                     'name' => $driver->name ?? 'Driver',
//                     'status' => "approved",
//                 ], function ($message) use ($driver) {
//                     $message->to($driver->email)
//                         ->subject('Document Status Updated');
//                 });
//             }
//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Document status updated successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function getDriverDocument(Request $request)
//     {
//         try {
//             $document = DriverDocument::where("id", $request->id)->first();

//             return response()->json([
//                 'success' => 1,
//                 'document' => $document
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function deleteDriverDocument(Request $request)
//     {
//         try {
//             $document = DriverDocument::where("id", $request->id)->first();

//             if (isset($document) && $document != NULL) {
//                 $document->delete();
//             }

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Document deleted successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function driverRideHistory(Request $request)
//     {
//         try {
//             $rideHistory = CompanyBooking::where('driver', $request->driver_id)->orderBy("id", "DESC")->get();

//             return response()->json([
//                 'success' => 1,
//                 'rideHistory' => $rideHistory
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function sendDriverNotification(Request $request)
//     {
//         try {
//             $request->validate([
//                 'driver_id' => 'required',
//                 'title' => 'required',
//                 'body' => 'required'
//             ]);

//             $driver = CompanyDriver::where("id", $request->driver_id)->first();

//             $tokens = CompanyToken::where("user_id", $request->driver_id)->where("user_type", "driver")->get();

//             if (isset($tokens) && $tokens != NULL) {
//                 foreach ($tokens as $key => $token) {
//                     FCMService::sendToDevice(
//                         $token->fcm_token,
//                         $request->title,
//                         $request->body,
//                     );
//                 }
//             }

//             $record = new CompanyNotification;
//             $record->user_type = "driver";
//             $record->user_id = $driver->id;
//             $record->title = $request->title;
//             $record->message = $request->body;
//             $record->save();

//             return response()->json([
//                 'success' => 1,
//                 'message' => 'Notification sent successfully'
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function pendingDocumentList(Request $request)
//     {
//         try {
//             $data = DriverDocument::where("status", "pending")->with(['driverDetail', 'documentDetail'])->paginate(10);

//             return response()->json([
//                 'success' => 1,
//                 "data" => $data
//             ]);
//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }

//     public function getDriverEarnings(Request $request)
//     {
//         try {
//             $request->validate([
//                 'driver_id' => 'required|exists:drivers,id',
//             ]);

//             // Get driver details
//             $driver = CompanyDriver::where("id", $request->driver_id)->first();

//             if (!$driver) {
//                 return response()->json([
//                     'error' => 1,
//                     'message' => 'Driver not found'
//                 ]);
//             }

//             // Get all completed bookings for this driver
//             $completedBookings = CompanyBooking::where('driver', $request->driver_id)
//                 ->where('booking_status', 'completed')
//                 ->get();

//             // Calculate total earnings
//             $totalEarnings = $completedBookings->sum('booking_amount');

//             // Get count of completed rides
//             $totalCompletedRides = $completedBookings->count();

//             return response()->json([
//                 'success' => 1,
//                 'driver_name' => $driver->name,
//                 'driver_id' => $driver->id,
//                 'total_completed_rides' => $totalCompletedRides,
//                 'total_earnings' => number_format($totalEarnings, 2, '.', ''),
//                 'bookings' => $completedBookings
//             ]);

//         } catch (\Exception $e) {
//             return response()->json([
//                 'error' => 1,
//                 'message' => $e->getMessage()
//             ]);
//         }
//     }
// }
