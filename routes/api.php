<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\SuperAdmin\CompanyController;
use App\Http\Controllers\SuperAdmin\OnboardingController;
use App\Http\Controllers\SuperAdmin\DocumentController;
use App\Http\Controllers\SuperAdmin\VehicleTypeController;
use App\Http\Controllers\SuperAdmin\HomeController;
use App\Http\Controllers\SuperAdmin\SubscriptionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('login', [AuthController::class, 'login']);

Route::group(['middleware' => ['auth:api']], function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    // Example: routes only for dispatchers and super_admin
    Route::group(['middleware' => ['role:superadmin']], function () {
        Route::post('/super-admin/update-profile', [HomeController::class, 'updateProfile']);
        Route::post('/super-admin/change-password', [HomeController::class, 'changePassword']);
        Route::get('/super-admin/dashboard', [HomeController::class, 'dashboard']);
        
        Route::post('/super-admin/create-company', [CompanyController::class, 'createCompany']);
        Route::get('/super-admin/edit-company', [CompanyController::class, 'getEditCompany']);
        Route::post('/super-admin/edit-company', [CompanyController::class, 'editCompany']);
        Route::get('/super-admin/company-cards', [CompanyController::class, 'companyCards']);
        Route::get('/super-admin/company-list', [CompanyController::class, 'companyList']);
        Route::get('/super-admin/company-details', [CompanyController::class, 'companyDetails']);

        Route::post('/super-admin/create-onboarding-request', [OnboardingController::class, 'createOnboardingRequest']);
        Route::post('/super-admin/edit-onboarding-request', [OnboardingController::class, 'editOnboardingRequest']);
        Route::post('/super-admin/change-onboarding-request-status', [OnboardingController::class, 'changeOnboardingRequestStatus']);
        Route::get('/super-admin/onboarding-request-list', [OnboardingController::class, 'onboardingRequestList']);
        
        Route::post('/super-admin/create-document', [DocumentController::class, 'createDocument']);
        Route::post('/super-admin/edit-document', [DocumentController::class, 'editDocument']);
        Route::get('/super-admin/document-list', [DocumentController::class, 'documentList']);
        Route::post('/super-admin/delete-document', [DocumentController::class, 'deleteDocument']);
        
        Route::post('/super-admin/create-vehicle-type', [VehicleTypeController::class, 'createVehicleType']);
        Route::post('/super-admin/edit-vehicle-type', [VehicleTypeController::class, 'editVehicleType']);
        Route::post('/super-admin/delete-vehicle-type', [VehicleTypeController::class, 'deleteVehicleType']);
        Route::get('/super-admin/vehicle-type-list', [VehicleTypeController::class, 'vehicleTypeList']);

        Route::get('/super-admin/subscription-cards', [SubscriptionController::class, 'subscriptionCards']);
        Route::post('/super-admin/create-subscription', [SubscriptionController::class, 'createSubscription']);
        Route::post('/super-admin/edit-subscription', [SubscriptionController::class, 'editSubscription']);
        Route::get('/super-admin/edit-subscription', [SubscriptionController::class, 'getEditSubscription']);
        Route::get('/super-admin/subscription-list', [SubscriptionController::class, 'subscriptionList']);
    });

    // Example: driver-only
    Route::group(['middleware' => ['role:driver']], function () {
        Route::get('driver-only', function () {
            return response()->json(['message' => 'hello driver']);
        });
    });
});
