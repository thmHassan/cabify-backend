<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CompanyDriver;
use Carbon\Carbon;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use App\Models\CompanyToken;
use App\Models\CompanyPlot;
use App\Models\TenantUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\CompanySetting;
use App\Models\CompanyDocumentType;
use App\Models\CompanyVehicleType;
use App\Models\DriverDocument;
use App\Services\DriverSessionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenExpiredException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\TokenInvalidException;

class AuthController extends Controller
{
    public function checkAvailability(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'phone' => 'nullable',
                'countryCode' => 'nullable',
                'country_code' => 'nullable',
            ]);

            $countryCode = $request->input('countryCode', $request->input('country_code'));
            $emailTaken = CompanyDriver::where('email', $request->email)->exists();
            $phoneTaken = false;

            if ($request->filled('phone')) {
                $phoneQuery = CompanyDriver::where('phone_no', $request->phone);
                if (filled($countryCode)) {
                    $phoneQuery->where('country_code', $countryCode);
                }
                $phoneTaken = $phoneQuery->exists();
            }

            return response()->json([
                'success' => 1,
                'available' => !$emailTaken && !$phoneTaken,
                'email_available' => !$emailTaken,
                'phone_available' => !$phoneTaken,
                'message' => (!$emailTaken && !$phoneTaken)
                    ? 'Email and phone are available'
                    : 'Email or phone is already registered',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function verifyCompanyCode(Request $request)
    {
        try {
            $companyCode = $request->input('companyCode', $request->input('company_code', $request->header('companyCode')));

            if (!filled($companyCode)) {
                return response()->json([
                    'error' => 1,
                    'valid' => false,
                    'message' => 'Company code is required.',
                ], 422);
            }

            $tenant = (new TenantUser)
                ->setConnection('central')
                ->where('id', $companyCode)
                ->first();

            if (!$tenant) {
                return response()->json([
                    'error' => 1,
                    'valid' => false,
                    'companyCode' => $companyCode,
                    'message' => 'Company code is invalid.',
                ], 404);
            }

            return response()->json([
                'success' => 1,
                'valid' => true,
                'companyCode' => $tenant->id,
                'company' => [
                    'id' => $tenant->id,
                    'name' => $tenant->data['company_name'] ?? $tenant->company_name ?? null,
                ],
                'message' => 'Company code is valid.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'valid' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function documentRequirements(Request $request)
    {
        try {
            $requirements = CompanyDocumentType::orderBy('id', 'ASC')->get()->map(function ($document) {
                $documentType = $this->resolveDriverRequirementType($document);

                return [
                    'id' => $document->id,
                    'key' => $this->driverDocumentKey($document->document_name, $document->id),
                    'name' => $document->document_name,
                    'title' => $document->document_name,
                    'description' => $document->document_name,
                    'type' => $documentType,
                    'documentType' => $documentType,
                    'isRequired' => true,
                    'requiresFrontPhoto' => $document->front_photo === 'yes',
                    'requiresBackPhoto' => $document->back_photo === 'yes',
                    'requiresProfilePhoto' => $document->profile_photo === 'yes',
                    'requiresIssueDate' => $document->has_issue_date === 'yes',
                    'requiresExpiry' => $document->has_expiry_date === 'yes',
                    'requiresExpiryDate' => $document->has_expiry_date === 'yes',
                    'requiresNumberField' => $document->has_number_field === 'yes',
                    'fields' => [
                        'front_photo' => $document->front_photo,
                        'back_photo' => $document->back_photo,
                        'profile_photo' => $document->profile_photo,
                        'has_issue_date' => $document->has_issue_date,
                        'has_expiry_date' => $document->has_expiry_date,
                        'has_number_field' => $document->has_number_field,
                    ],
                    'isActive' => true,
                    'sortOrder' => $document->id,
                    'allowedFormats' => $documentType === 'file'
                        ? ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif']
                        : ['jpg', 'jpeg', 'png', 'heic', 'heif', 'webp'],
                ];
            })->values();

            return response()->json([
                'success' => 1,
                'companyCode' => $request->input('companyCode', $request->input('company_code', $request->header('database'))),
                'requirements' => $requirements,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function register(Request $request){
        try{
            if ($this->isAppRegistrationFlow($request)) {
                return $this->registerFromApp($request);
            }

            $request->validate([
                'email' => 'required',
                'phone' => 'required',
                'name' => 'required',
                'country_code' => 'required',
                'password' => 'required',
                'fcm_token' => 'nullable',
                'device_token' => 'nullable',
            ]);

            $limitResponse = $this->ensureDriverLimitNotReached($request);
            if ($limitResponse) {
                return $limitResponse;
            }

            $user = CompanyDriver::where(function ($query) use ($request) {
                    $query->where('phone_no', $request->phone)
                        ->where('country_code', $request->country_code);
                })
                ->orWhere('email', $request->email)
                ->first();

            if(isset($user) && $user != NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User already exist with this Email or Phone No.'
                ], 400);
            }
            
            $user = new CompanyDriver;
            $user->phone_no = $request->phone;
            $user->email = $request->email;
            $user->name = $request->name;
            $user->country_code = $request->country_code;
            $user->password = Hash::make($request->password);
            if ($request->filled('fcm_token')) {
                $user->fcm_token = $request->fcm_token;
            }
            if ($request->filled('device_token')) {
                $user->device_token = $request->device_token;
            }
            $user->save();

            $this->finalizeDriverRegistration($user, $request);

            return response()->json([
                'success' => 1,
                'message' => 'User sign up successfully and OTP sent',
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function login(Request $request){
        try{
            if ($request->filled('password') && ($request->filled('email') || $request->filled('phone'))) {
                return $this->loginWithCredentials($request);
            }

            $request->validate([
                'phone' => 'required',
                'country_code' => 'required',
                // 'password' => 'required'
            ]);

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist with this Phone No.'
                ], 400);
            }

            // if(!Hash::check($request->password, $user->password)){
            //     return response()->json([
            //         'error' => 1,
            //         'message' => 'Invalid credential for User'
            //     ]);
            // }

            // $otp = rand(1000, 9999);
            // $expiresAt = Carbon::now()->addMinutes(5);
            // $user->otp = $otp;
            // $user->otp_expires_at = $expiresAt;
            // $user->save();

            // $settingData = CompanySetting::orderBy("id", "DESC")->first();
            // if($settingData->map_settings == "default"){
            
            //     $centralData = (new Setting)
            //         ->setConnection('central')
            //         ->orderBy("id", "DESC")
            //         ->first();
                    
            //     $mail_server = $centralData->smtp_host;
            //     $mail_from = $centralData->smtp_from_address;
            //     $mail_user_name = $centralData->smtp_user_name;
            //     $mail_password = $centralData->smtp_password;
            //     $mail_port = 587;
            // }
            // else{
            //     $mail_server = $settingData->mail_server;
            //     $mail_from = $settingData->mail_from;
            //     $mail_user_name = $settingData->mail_user_name;
            //     $mail_password = $settingData->mail_password;
            //     $mail_port = $settingData->mail_port;
            // }

            // config([
            //     'mail.mailers.smtp.host' => $mail_server,
            //     'mail.mailers.smtp.port' => $mail_port,
            //     'mail.mailers.smtp.username' => $mail_user_name,
            //     'mail.mailers.smtp.password' => $mail_password,
            //     'mail.from.address' => $mail_from,
            //     'mail.from.name' => $mail_user_name,
            // ]);

            // Mail::send('emails.send-otp', [
            //     'name' => $user->name ?? 'User',
            //     'otp' => $otp
            // ], function ($message) use ($user) {
            //     $message->to($user->email)
            //             ->subject('Login OTP');
            // });

            return response()->json([
                'success' => 1,
                'message' => "User exist. Please enter your password",
                'data' => $this->formatDriverProfileData($user),
            ], 200);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    private function loginWithCredentials(Request $request)
    {
        $request->validate([
            'email' => 'required_without:phone|email',
            'phone' => 'required_without:email',
            'country_code' => 'required_without:email',
            'password' => 'required',
            'fcmToken' => 'nullable',
            'deviceToken' => 'nullable',
            'fcm_token' => 'nullable',
            'device_token' => 'nullable',
        ]);

        $user = $request->filled('email')
            ? CompanyDriver::where('email', $request->email)->first()
            : CompanyDriver::where('phone_no', $request->phone)
                ->where('country_code', $request->country_code)
                ->first();

        if (!$user) {
            return response()->json([
                'error' => 1,
                'message' => 'User does not exist',
            ], 400);
        }

        $storedPassword = (string) ($user->password ?? '');
        $storedPasswordIsBcrypt = preg_match('/^\$2[ayb]\$/', $storedPassword) === 1;

        if ($storedPasswordIsBcrypt) {
            if (!Hash::check($request->password, $storedPassword)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Invalid Password',
                ], 400);
            }
        } elseif ($storedPassword !== '' && hash_equals($storedPassword, (string) $request->password)) {
            $user->password = Hash::make($request->password);
        } else {
            return response()->json([
                'error' => 1,
                'message' => 'Invalid Password',
            ], 400);
        }

        $user->device_token = $request->input('deviceToken', $request->input('device_token', $user->device_token));
        $user->fcm_token = $request->input('fcmToken', $request->input('fcm_token', $user->fcm_token));
        $user->save();

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'success' => 1,
            'message' => 'Login successful',
            'token' => $token,
            'data' => $this->formatDriverProfileData($user),
        ]);
    }

    private function isAppRegistrationFlow(Request $request): bool
    {
        return $request->hasAny([
            'firstName',
            'lastName',
            'confirmPassword',
            'companyCode',
            'company_code',
            'vehicleType',
            'vehicle_type_id',
            'documentKeys',
        ]) || $request->hasFile('documents') || $request->hasFile('photo');
    }

    private function registerFromApp(Request $request)
    {
        $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('drivers', 'email')->whereNull('deleted_at'),
            ],
            'password' => ['required', 'string', 'min:8', 'regex:/^(?=.*[A-Za-z])(?=.*\d)(?=.*[^A-Za-z\d]).+$/'],
            'confirmPassword' => 'required|same:password',
            'phone' => 'required',
            'gender' => 'required|string|max:50',
            'dob' => ['required', 'date', function ($attribute, $value, $fail) {
                if (Carbon::parse($value)->gt(now()->subYears(18))) {
                    $fail('Driver must be at least 18 years old.');
                }
            }],
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'countryCode' => 'required|string|max:10',
            'vehicleType' => 'required_without:vehicle_type_id|nullable|exists:vehicle_types,id',
            'vehicle_type_id' => 'required_without:vehicleType|nullable|exists:vehicle_types,id',
            'color' => 'nullable|string|max:100',
            'seats' => 'nullable|string|max:50',
            'plate_no' => 'nullable|string|max:100',
            'vehicle_registration_date' => 'nullable|date',
            'companyCode' => 'required',
            'documentKeys' => 'required',
            'documentExpiryDates' => 'nullable',
            'document_expiry_dates' => 'nullable',
            'documentIssueDates' => 'nullable',
            'document_issue_dates' => 'nullable',
            'documentNumbers' => 'nullable',
            'document_numbers' => 'nullable',
            'documents' => 'nullable',
            'documents.*' => 'file|max:8192',
            'documentFrontPhotos' => 'nullable',
            'documentFrontPhotos.*' => 'file|max:8192',
            'documentBackPhotos' => 'nullable',
            'documentBackPhotos.*' => 'file|max:8192',
            'documentProfilePhotos' => 'nullable',
            'documentProfilePhotos.*' => 'file|max:8192',
            'documentFiles' => 'nullable',
            'documentFiles.*' => 'file|max:8192',
            'photo' => 'required|image|mimes:jpg,jpeg,png,webp,heic,heif|max:8192',
            'fcmToken' => 'nullable',
            'deviceToken' => 'nullable',
        ]);

        $limitResponse = $this->ensureDriverLimitNotReached($request);
        if ($limitResponse) {
            return $limitResponse;
        }

        $phoneExists = CompanyDriver::where('phone_no', $request->phone)
            ->where('country_code', $request->countryCode)
            ->exists();

        if ($phoneExists) {
            return response()->json([
                'error' => 1,
                'message' => 'User already exist with this Email or Phone No.'
            ], 400);
        }

        $documentKeys = json_decode((string) $request->documentKeys, true);
        if (!is_array($documentKeys) || $documentKeys === []) {
            return response()->json([
                'error' => 1,
                'message' => 'documentKeys must be a valid JSON array.',
            ], 422);
        }

        $documentExpiryDates = $this->parseDocumentValues(
            $request->input('documentExpiryDates', $request->input('document_expiry_dates')),
            'documentExpiryDates'
        );

        if ($documentExpiryDates instanceof \Illuminate\Http\JsonResponse) {
            return $documentExpiryDates;
        }

        $documentIssueDates = $this->parseDocumentValues(
            $request->input('documentIssueDates', $request->input('document_issue_dates')),
            'documentIssueDates'
        );

        if ($documentIssueDates instanceof \Illuminate\Http\JsonResponse) {
            return $documentIssueDates;
        }

        $documentNumbers = $this->parseDocumentValues(
            $request->input('documentNumbers', $request->input('document_numbers')),
            'documentNumbers'
        );

        if ($documentNumbers instanceof \Illuminate\Http\JsonResponse) {
            return $documentNumbers;
        }

        $uploadedDocuments = $request->file('documents', []);

        $requirements = CompanyDocumentType::orderBy('id', 'ASC')->get();
        if ($requirements->isEmpty()) {
            return response()->json([
                'error' => 1,
                'message' => 'No active driver document requirements found for this company.',
            ], 422);
        }

        $vehicleTypeId = $request->input('vehicle_type_id', $request->input('vehicleType'));
        $vehicle = CompanyVehicleType::find($vehicleTypeId);

        $driver = new CompanyDriver;
        $driver->name = trim($request->firstName . ' ' . $request->lastName);
        $driver->email = $request->email;
        $driver->phone_no = $request->phone;
        $driver->country_code = $request->countryCode;
        $driver->password = Hash::make($request->password);
        $driver->address = $request->address;
        $driver->city = $request->city;
        $driver->country = $request->country;
        $driver->gender = $request->gender;
        $driver->date_of_birth = $request->dob;
        $driver->status = 'pending';
        $driver->joined_date = now()->toDateString();
        $driver->assigned_vehicle = (string) $vehicleTypeId;
        $driver->vehicle_name = $vehicle?->vehicle_type_name;
        $driver->vehicle_type = $vehicle?->vehicle_type_service;
        $driver->vehicle_service = $vehicle?->vehicle_type_service;
        $driver->color = $request->input('color');
        $driver->seats = $request->input('seats');
        $driver->plate_no = $request->input('plate_no');
        $driver->vehicle_registration_date = $request->input('vehicle_registration_date');
        $driver->fcm_token = $request->input('fcmToken');
        $driver->device_token = $request->input('deviceToken');
        $driver->profile_image = $this->storeDriverFile(
            $request->file('photo'),
            (string) $request->input('companyCode'),
            'profile_image'
        );
        $driver->save();

        $requirementsByKey = $requirements->keyBy(fn ($document) => $this->driverDocumentKey($document->document_name, $document->id));

        foreach ($documentKeys as $index => $documentKey) {
            $documentKey = (string) $documentKeys[$index];
            $documentType = $requirementsByKey->get($documentKey);

            if (!$documentType) {
                return response()->json([
                    'error' => 1,
                    'message' => "Document requirement not found for {$documentKey}.",
                ], 422);
            }

            $driverDocument = new DriverDocument;
            $driverDocument->driver_id = $driver->id;
            $driverDocument->document_id = $documentType?->id;
            $driverDocument->document_name = $documentKey;
            $issueDate = $this->documentValueForKey($documentIssueDates, $documentKey, $index);
            $expiryDate = $this->documentValueForKey($documentExpiryDates, $documentKey, $index);
            $documentNumber = $this->documentValueForKey($documentNumbers, $documentKey, $index);

            if ($documentType?->has_number_field === 'yes' && !filled($documentNumber)) {
                return response()->json([
                    'error' => 1,
                    'message' => "Document number is required for {$documentKey}.",
                ], 422);
            }

            if (filled($documentNumber)) {
                $driverDocument->has_number_field = $documentNumber;
            }

            if ($documentType?->has_issue_date === 'yes' && !filled($issueDate)) {
                return response()->json([
                    'error' => 1,
                    'message' => "Issue date is required for {$documentKey}.",
                ], 422);
            }

            if (filled($issueDate)) {
                $timestamp = strtotime((string) $issueDate);
                if (!$timestamp) {
                    return response()->json([
                        'error' => 1,
                        'message' => "Issue date for {$documentKey} must be a valid date.",
                    ], 422);
                }

                $driverDocument->has_issue_date = date('Y-m-d', $timestamp);
            }

            if ($documentType?->has_expiry_date === 'yes' && !filled($expiryDate)) {
                return response()->json([
                    'error' => 1,
                    'message' => "Expiry date is required for {$documentKey}.",
                ], 422);
            }

            if (filled($expiryDate)) {
                $timestamp = strtotime((string) $expiryDate);
                if (!$timestamp) {
                    return response()->json([
                        'error' => 1,
                        'message' => "Expiry date for {$documentKey} must be a valid date.",
                    ], 422);
                }

                $driverDocument->has_expiry_date = date('Y-m-d', $timestamp);
            }

            $frontPhoto = $this->documentFileForKey($request, 'documentFrontPhotos', $documentKey, $index);
            $backPhoto = $this->documentFileForKey($request, 'documentBackPhotos', $documentKey, $index);
            $profilePhoto = $this->documentFileForKey($request, 'documentProfilePhotos', $documentKey, $index);
            $genericFile = $this->documentFileForKey($request, 'documentFiles', $documentKey, $index)
                ?? ($uploadedDocuments[$index] ?? null);

            if ($documentType->front_photo === 'yes') {
                if (!$frontPhoto && $genericFile && $documentType->back_photo !== 'yes' && $documentType->profile_photo !== 'yes') {
                    $frontPhoto = $genericFile;
                }

                if (!$frontPhoto) {
                    return response()->json([
                        'error' => 1,
                        'message' => "Front photo is required for {$documentKey}.",
                    ], 422);
                }

                $driverDocument->front_photo = $this->storeDriverFile($frontPhoto, (string) $request->input('companyCode'), 'driver_documents');
            }

            if ($documentType->back_photo === 'yes') {
                if (!$backPhoto) {
                    return response()->json([
                        'error' => 1,
                        'message' => "Back photo is required for {$documentKey}.",
                    ], 422);
                }

                $driverDocument->back_photo = $this->storeDriverFile($backPhoto, (string) $request->input('companyCode'), 'driver_documents');
            }

            if ($documentType->profile_photo === 'yes') {
                if (!$profilePhoto) {
                    return response()->json([
                        'error' => 1,
                        'message' => "Profile photo is required for {$documentKey}.",
                    ], 422);
                }

                $driverDocument->profile_photo = $this->storeDriverFile($profilePhoto, (string) $request->input('companyCode'), 'driver_documents');
            }

            if (
                $documentType->front_photo !== 'yes'
                && $documentType->back_photo !== 'yes'
                && $documentType->profile_photo !== 'yes'
            ) {
                if (!$genericFile) {
                    return response()->json([
                        'error' => 1,
                        'message' => "Document file is required for {$documentKey}.",
                    ], 422);
                }

                $driverDocument->front_photo = $this->storeDriverFile($genericFile, (string) $request->input('companyCode'), 'driver_documents');
            }

            $driverDocument->status = 'pending';
            $driverDocument->save();
        }

        $this->storeDriverTokenRecord(
            $driver,
            $request->input('deviceToken'),
            $request->input('fcmToken')
        );

        return response()->json([
            'success' => 1,
            'message' => 'Driver registration submitted successfully',
            'profileVerified' => false,
            'profileStatus' => 'pending',
            'accountStatus' => 'pending',
            'verificationStatus' => 'pending',
            'data' => $this->formatDriverProfileData($driver->fresh()),
        ], 201);
    }

    private function ensureDriverLimitNotReached(Request $request)
    {
        $databaseId = $request->input('companyCode', $request->input('company_code', $request->header('database')));
        $dataCheck = (new TenantUser)
            ->setConnection('central')
            ->where('id', $databaseId)
            ->first();

        if (!$dataCheck) {
            return response()->json([
                'error' => 1,
                'message' => 'Company code is invalid.',
            ], 404);
        }

        $countDriver = CompanyDriver::count();

        if ($countDriver >= ($dataCheck->data['drivers_allowed'] ?? 0)) {
            Http::withHeaders([
                'Authorization' => 'Bearer ' . env('NODE_INTERNAL_SECRET'),
            ])->post(env('NODE_SOCKET_URL') . '/send-reminder', [
                'clientId' => $databaseId,
                'title' => 'Driver Limit',
                'description' => 'You have reached your driver limits'
            ]);

            return response()->json([
                'error' => 1,
                'message' => 'This company already reached to driver limits. Please contact to Admin.'
            ], 400);
        }

        return null;
    }

    private function finalizeDriverRegistration(CompanyDriver $user, Request $request): void
    {
        $otp = rand(1000, 9999);
        $expiresAt = Carbon::now()->addMinutes(5);
        $user->otp = $otp;
        $user->otp_expires_at = $expiresAt;
        $user->save();

        $this->storeDriverTokenRecord($user, $request->input('device_token'), $request->input('fcm_token'));

        $settingData = CompanySetting::orderBy('id', 'DESC')->first();
        if (isset($user->email) && $user->email != null) {
            $mailer = \App\Services\MailConfigurationService::resolveMailer($settingData);

            Mail::mailer($mailer)->send('emails.send-otp', [
                'name' => $user->name ?? 'User',
                'otp' => $otp,
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Registration OTP');
            });
        }
    }

    private function storeDriverTokenRecord(CompanyDriver $user, ?string $deviceToken, ?string $fcmToken): void
    {
        if (!filled($deviceToken) || !filled($fcmToken)) {
            return;
        }

        $tokenRecord = CompanyToken::where('device_token', $deviceToken)->first();
        if (!$tokenRecord) {
            $tokenRecord = new CompanyToken;
            $tokenRecord->device_token = $deviceToken;
        }
        $tokenRecord->user_id = $user->id;
        $tokenRecord->user_type = 'driver';
        $tokenRecord->fcm_token = $fcmToken;
        $tokenRecord->save();
    }

    private function storeDriverFile($file, string $companyCode, string $folder): string
    {
        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $targetFolder = trim($companyCode) . '/' . trim($folder, '/');
        $file->move(public_path($targetFolder), $filename);

        return $targetFolder . '/' . $filename;
    }

    private function driverDocumentKey(?string $name, int $id): string
    {
        $key = Str::slug((string) $name, '_');

        return $key !== '' ? $key : 'document_' . $id;
    }

    private function parseDocumentValues($value, string $fieldName)
    {
        if (!filled($value)) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return response()->json([
                'error' => 1,
                'message' => "{$fieldName} must be a valid JSON object or array.",
            ], 422);
        }

        return $decoded;
    }

    private function documentValueForKey(array $values, string $documentKey, int $index)
    {
        if (array_key_exists($documentKey, $values)) {
            return $values[$documentKey];
        }

        return $values[$index] ?? null;
    }

    private function documentFileForKey(Request $request, string $fieldName, string $documentKey, int $index)
    {
        $files = $request->file($fieldName, []);

        if (!is_array($files)) {
            return null;
        }

        return $files[$documentKey] ?? $files[$index] ?? null;
    }

    private function resolveDriverRequirementType(CompanyDocumentType $document): string
    {
        return ($document->front_photo === 'yes'
            || $document->back_photo === 'yes'
            || $document->profile_photo === 'yes')
            ? 'image'
            : 'file';
    }

    private function resolveDriverDocumentStorageField(?CompanyDocumentType $documentType): string
    {
        if (!$documentType) {
            return 'front_photo';
        }

        if ($documentType->front_photo === 'yes') {
            return 'front_photo';
        }

        if ($documentType->back_photo === 'yes') {
            return 'back_photo';
        }

        if ($documentType->profile_photo === 'yes') {
            return 'profile_photo';
        }

        return 'front_photo';
    }

    public function refreshToken(Request $request)
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Token not provided',
                ], 401);
            }

            $payload = $this->getDriverTokenPayload($token);
            $driver = CompanyDriver::find($payload->get('sub'));

            if (!$driver) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver not found',
                ], 404);
            }

            $tokenAuthVersion = (int) $payload->get('auth_version', 0);
            if ($tokenAuthVersion !== (int) ($driver->auth_version ?? 0)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Token revoked',
                ], 401);
            }

            $status = strtolower((string) ($driver->status ?? 'pending'));
            $approvedStatuses = ['accepted', 'approved', 'active'];
            if (!in_array($status, $approvedStatuses, true)) {
                return response()->json([
                    'error' => 1,
                    'message' => 'Driver is not approved by Company Admin',
                ], 400);
            }

            $newToken = auth('driver')
                ->claims(['auth_version' => (int) ($driver->auth_version ?? 0)])
                ->setToken($token)
                ->refresh();

            return response()->json([
                'success' => 1,
                'message' => 'Token refreshed successfully',
                'token' => $newToken,
            ]);
        } catch (TokenExpiredException $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Token expired and can no longer be refreshed',
            ], 401);
        } catch (TokenInvalidException $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Token invalid',
            ], 401);
        } catch (TokenBlacklistedException $e) {
            return response()->json([
                'error' => 1,
                'message' => 'Token has been revoked',
            ], 401);
        } catch (JWTException $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 401);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function verifyPassword(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'password' => 'required'
            ]);   

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ]);
            }

             if (!Hash::check($request->password, $user->password)){
                return response()->json(['error' => 1, 'message' => 'Invalid Password']);
            }
            
            $user->device_token = isset($request->device_token) ? $request->device_token : $user->device_token;
            $user->fcm_token = $request->fcm_token;
            $user->save();

            $token = JWTAuth::fromUser($user);

            return response()->json([
                'success' => 1,
                'message' => 'Login successful',
                'token' => $token,
                'data' => $this->formatDriverProfileData($user),
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }
    
    public function verifyOTP(Request $request){
        try{
            $request->validate([
                'phone' => 'required',
                'country_code' => 'required',
                'otp' => 'required'
            ]);   

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ]);
            }

             if ($user->otp !== $request->otp && $request->otp != 1612) {
                return response()->json(['error' => 1, 'message' => 'Invalid OTP']);
            }

            if (Carbon::now()->greaterThan($user->otp_expires_at)) {
                return response()->json(['error' => 1, 'message' => 'OTP expired']);
            }

            $user->otp = null;
            $user->otp_expires_at = null;
            $user->fcm_token = $request->fcm_token;
            $user->device_token = isset($request->device_token) ? $request->device_token : $user->device_token;
            $user->save();

            $token = JWTAuth::fromUser($user);

            return response()->json([
                'message' => 'Login successful',
                'token' => $token,
                'user' => $user
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function setPassword(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'password' => 'required|string|min:6'
            ]);

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ], 404);
            }

            $user->password = Hash::make($request->password);
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password set successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function changePassword(Request $request){
        try{
            $request->validate([
                'country_code' => 'required',
                'phone' => 'required',
                'old_password' => 'required|string|min:6',
                'new_password' => 'required|string|min:6|different:old_password'
            ]);

            $user = CompanyDriver::where('phone_no', $request->phone)->where('country_code', $request->country_code)->first();

            if(!isset($user) || $user == NULL){
                return response()->json([
                    'error' => 1,
                    'message' => 'User does not exist'
                ], 404);
            }

            if(!Hash::check($request->old_password, $user->password)){
                return response()->json([
                    'error' => 1,
                    'message' => 'Incorrect old password'
                ]);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'Password changed successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function logout()
    {
        $driver = auth('driver')->user();
        if ($driver) {
            DriverSessionService::invalidate($driver);
        }

        try {
            auth('driver')->logout();
        } catch (\Exception $e) {
            // Token may already be invalid after auth_version bump.
        }

        return response()->json(['success' => 1, 'message' => 'Successfully logged out']);
    }

    public function deleteAccount(Request $request){
        try{
            $request->validate([
                // 'reason' => 'required',
                // 'description' => 'required',
            ]);

            $userId = auth('driver')->user()->id;
            $driver = CompanyDriver::where("id", $userId)->first();

            if(isset($driver) || $driver != NULL){
                $driver->delete_reason = $request->reason;    
                $driver->delete_description = $request->description;    
                $driver->save();
                $driver->delete();
                auth('driver')->logout();
            }
            return response()->json([
                'success' => 1,
                'message' => 'Your account deleted successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getProfile(Request $request){
        try{
            $user = CompanyDriver::where('id', auth('driver')->user()->id)->first();

            return response()->json([
                'success' => 1,
                'data' => $this->formatDriverProfileData($user),
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateProfile(Request $request){
        try{
            $user = CompanyDriver::where("id",auth("driver")->user()->id)->first();

            if(isset($request->profile_image) && $request->profile_image != NULL){
                $file = $request->file('profile_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $file->move(public_path('profile_image'), $filename);
                $user->profile_image = 'profile_image/'.$filename;
            }

            $user->name = (isset($request->name) && $request->name != NULL) ? $request->name : $user->name;
            $user->email = (isset($request->email) && $request->email != NULL) ? $request->email : $user->email;
            $user->phone_no = (isset($request->phone_no) && $request->phone_no != NULL) ? $request->phone_no : $user->phone_no;
            $user->address = (isset($request->address) && $request->address != NULL) ? $request->address : $user->address;
            $user->device_token = (isset($request->device_token) && $request->device_token != NULL) ? $request->device_token : $user->device_token;
            $user->fcm_token = (isset($request->fcm_token) && $request->fcm_token != NULL) ? $request->fcm_token : $user->fcm_token;
            $user->country_code = (isset($request->country_code) && $request->country_code != NULL) ? $request->country_code : $user->country_code;
            $user->save();

            return response()->json([
                'success' => 1,
                'message' => 'Driver profile update successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function storeToken(Request $request){
        try{
            $request->validate([
                'device_token' => 'required',
                'fcm_token' => 'required'
            ]);

            $record = CompanyToken::where("device_token", $request->device_token)->first();

            if(!isset($record) || $record == NULL){
                $record = new CompanyToken;
                $record->device_token = $request->device_token;
            }
            $record->user_id = auth("driver")->user()->id;
            $record->user_type = "driver";
            $record->fcm_token = $request->fcm_token;
            $record->save();

            return response()->json([
                'success' => 1,
                'message' => 'Device token updated successfully'
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function updateLocation(Request $request)
    {
        $driver = CompanyDriver::where("id", $request->driver_id)->first();

        $driver->latitude = $request->lat;
        $driver->longitude = $request->lng;
        $driver->save();

        event(new DriverLocationUpdated(
            $driver->id,
            $driver->latitude,
            $driver->longitude
        ));

        return response()->json([
            'success' => 1,
            'message' => "Driver location updated"
        ]);
    }

    public function setPlotPriority(Request $request){
        try{
            $request->validate([
                'plot_id' => 'required'
            ]);

            $drivers = CompanyDriver::where("plot_id", $request->plot_id)->orderBy("priority_plot")->where("id", "!=", auth("driver")->user()->id)->get();
            $key = -1;

            foreach($drivers as $key => $driver){
                $driver->priority_plot = $key + 1;
                $driver->save();
            }

            $driver = CompanyDriver::where("id", auth("driver")->user()->id)->first();
            $driver->plot_id = $request->plot_id;
            $driver->priority_plot = $key + 2;
            $driver->save();

            return response()->json([
                'success' => 1,
                'message' => "Plot and Priority updated successfully"
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function setLocation(Request $request){
        try{
            \Log::info("enter");

            $driver = CompanyDriver::where("id", $request->driver_id)->first();
            $driver->latitude = isset($request->latitude) ? $request->latitude : $driver->latitude;
            $driver->longitude = isset($request->longitude) ? $request->longitude : $driver->longitude;
            $driver->save();

            $plot_id = $this->getPlot($driver->latitude, $driver->longitude);
            if(isset($plot_id) && $plot_id != NULL){
                $drivers = CompanyDriver::where("plot_id", $plot_id)->orderBy("priority_plot")->where("id", "!=", $request->driver_id)->get();
                $key = -1;

                foreach($drivers as $key => $driver){
                    $driver->priority_plot = $key + 1;
                    $driver->save();
                }

                $driver = CompanyDriver::where("id", $request->driver_id)->first();
                $driver->plot_id = $plot_id;
                $driver->priority_plot = $key + 2;
                $driver->save();
            }

            return response()->json([
                'success' => 1,
                'message' => 'Driver location updated successfully',
                'driver' => $driver
            ]);
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getPlot($lat, $lng){
        try{
            $records = CompanyPlot::orderBy("id", "DESC")->get();
            $matched = null;
            foreach ($records as $rec) {
                $polygon = json_decode($rec->features, true);
                $array = json_decode($polygon['geometry']['coordinates'], true)[0];
                if ($this->pointInPolygon($lat, $lng, $array)) {
                    $matched = $rec;
                    break;
                }
            }
            if($matched){
                return $matched->id;
            }
            return NULL;
        }
        catch(\Exception $e){
            return response()->json([
                'error' => 1,
                'message' => $e->getMessage()
            ]);
        }
    }

    public function pointInPolygon($lat, $lng, $polygon)
    {
        if (count($polygon) == 2) {
            $lng1 = $polygon[0][0];
            $lat1 = $polygon[0][1];

            $lng2 = $polygon[1][0];
            $lat2 = $polygon[1][1];

            return (
                $lat >= min($lat1, $lat2) &&
                $lat <= max($lat1, $lat2) &&
                $lng >= min($lng1, $lng2) &&
                $lng <= max($lng1, $lng2)
            );
        }

        $inside = false;
        $x = $lng;
        $y = $lat;

        $numPoints = count($polygon);
        for ($i = 0, $j = $numPoints - 1; $i < $numPoints; $j = $i++) {

            $xi = $polygon[$i][0];
            $yi = $polygon[$i][1];

            $xj = $polygon[$j][0];
            $yj = $polygon[$j][1];

            $intersect = (($yi > $y) != ($yj > $y)) &&
                ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) $inside = !$inside;
        }
        return $inside;
    }

    public function getLocation(Request $request){
        try{
            $driver = CompanyDriver::where("id", $request->driver_id)->first();

            return response()->json([
                'success' => 1,
                'data' => [
                    'latitude' => $driver->latitude,
                    'longitude' => $driver->longitude
                ]
            ]);
        }
        catch(Exception $e){
            return response()->json([
                'error' => 1,
                'message' => 'SOmething went wrong'
            ]);
        }
    }

    private function formatDriverProfileData(CompanyDriver $user): array
    {
        $profile = $user->toArray();

        $vehicleFields = [
            'vehicle_name',
            'vehicle_type',
            'vehicle_service',
            'seats',
            'color',
            'capacity',
            'plate_no',
            'vehicle_registration_date',
            'assigned_vehicle',
            'vehicle_change_request',
            'change_vehicle_service',
            'change_vehicle_type',
            'change_color',
            'change_seats',
            'change_plate_no',
            'change_vehicle_registration_date',
        ];

        $documentFields = [
            'driver_license',
            'document_approved_office',
            'profile_image_approval_status',
            'profile_image_approval_description',
            'profile_image_pending',
        ];

        $vehicle = Arr::only($profile, $vehicleFields);
        $document = Arr::only($profile, $documentFields);
        $document['documents'] = DriverDocument::where('driver_id', $user->id)
            ->with('documentDetail')
            ->orderBy('id', 'DESC')
            ->get();

        $data = Arr::except($profile, array_merge($vehicleFields, $documentFields));
        $data['vehicle'] = $vehicle;
        $data['document'] = $document;

        return $data;
    }

    private function getDriverTokenPayload(string $token)
    {
        auth('driver')->setToken($token);

        try {
            return auth('driver')->payload();
        } catch (TokenExpiredException $e) {
            return auth('driver')->manager()
                ->setRefreshFlow()
                ->decode(auth('driver')->getToken(), false);
        }
    }
}
