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
use App\Http\Controllers\SuperAdmin\SubadminController;
use App\Http\Controllers\SuperAdmin\PlotController;
use App\Http\Controllers\Company\DispatcherController;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

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

Route::post('/super-admin/stripe-webhook', [CompanyController::class, 'stripeWebhook']);
Route::post('login', [AuthController::class, 'login']);
Route::post('/company/login', [CompanyController::class, 'companyLogin']);

Route::group(['middleware' => ['auth:api']], function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::group(['middleware' => ['role:superadmin,subadmin']], function () {
        Route::post('/super-admin/update-profile', [HomeController::class, 'updateProfile']);
        Route::post('/super-admin/change-password', [HomeController::class, 'changePassword']);
        Route::get('/super-admin/dashboard', [HomeController::class, 'dashboard']);
        Route::get('/super-admin/usage-monitoring', [HomeController::class, 'usageMonitoring']);
        Route::get('/super-admin/api-keys', [HomeController::class, 'getAPIKeys']);
        Route::post('/super-admin/api-keys', [HomeController::class, 'storeAPIKeys']);
        
        Route::post('/super-admin/create-company', [CompanyController::class, 'createCompany']);
        Route::get('/super-admin/edit-company', [CompanyController::class, 'getEditCompany']);
        Route::post('/super-admin/edit-company', [CompanyController::class, 'editCompany']);
        Route::get('/super-admin/company-cards', [CompanyController::class, 'companyCards']);
        Route::get('/super-admin/company-list', [CompanyController::class, 'companyList']);
        Route::get('/super-admin/company-details', [CompanyController::class, 'companyDetails']);
        Route::get('/super-admin/company-subscription-list', [CompanyController::class, 'subscriptionList']);
        Route::post('/super-admin/cash-payment', [CompanyController::class, 'cashPayment']);
        Route::post('/super-admin/create-stripe-payment-url', [CompanyController::class, 'createStripePaymentUrl']);

        Route::post('/super-admin/create-onboarding-request', [OnboardingController::class, 'createOnboardingRequest']);
        Route::post('/super-admin/edit-onboarding-request', [OnboardingController::class, 'editOnboardingRequest']);
        Route::post('/super-admin/change-onboarding-request-status', [OnboardingController::class, 'changeOnboardingRequestStatus']);
        Route::get('/super-admin/onboarding-request-list', [OnboardingController::class, 'onboardingRequestList']);
        
        Route::post('/super-admin/create-document', [DocumentController::class, 'createDocument']);
        Route::post('/super-admin/edit-document', [DocumentController::class, 'editDocument']);
        Route::get('/super-admin/edit-document', [DocumentController::class, 'getEditDocument']);
        Route::get('/super-admin/document-list', [DocumentController::class, 'documentList']);
        Route::post('/super-admin/delete-document', [DocumentController::class, 'deleteDocument']);
        
        Route::post('/super-admin/create-vehicle-type', [VehicleTypeController::class, 'createVehicleType']);
        Route::post('/super-admin/edit-vehicle-type', [VehicleTypeController::class, 'editVehicleType']);
        Route::post('/super-admin/delete-vehicle-type', [VehicleTypeController::class, 'deleteVehicleType']);
        Route::get('/super-admin/vehicle-type-list', [VehicleTypeController::class, 'vehicleTypeList']);
        Route::get('/super-admin/all-vehicle-type-list', [VehicleTypeController::class, 'allVehicleTypeList']);

        Route::get('/super-admin/subscription-cards', [SubscriptionController::class, 'subscriptionCards']);
        Route::post('/super-admin/create-subscription', [SubscriptionController::class, 'createSubscription']);
        Route::post('/super-admin/edit-subscription', [SubscriptionController::class, 'editSubscription']);
        Route::get('/super-admin/edit-subscription', [SubscriptionController::class, 'getEditSubscription']);
        Route::get('/super-admin/subscription-list', [SubscriptionController::class, 'subscriptionList']);
        
        Route::post('/super-admin/create-subadmin', [SubadminController::class, 'createSubadmin']);
        Route::post('/super-admin/edit-subadmin', [SubadminController::class, 'editSubadmin']);
        Route::get('/super-admin/edit-subadmin', [SubadminController::class, 'getEditSubadmin']);
        Route::get('/super-admin/subadmin-list', [SubadminController::class, 'subadminList']);
        Route::get('/super-admin/get-subadmin-permission', [SubadminController::class, 'getSubadminPermission']);

        Route::post('/super-admin/create-plot', [PlotController::class, 'createPlot']);
        Route::post('/super-admin/edit-plot', [PlotController::class, 'editPlot']);
        Route::get('/super-admin/edit-plot', [PlotController::class, 'getEditPlot']);
        Route::get('/super-admin/plot-list', [PlotController::class, 'plotList']);
        Route::get('/super-admin/delete-plot', [PlotController::class, 'deletePlot']);
    });
});

Route::group(['middleware' => ['auth.tenant.jwt']], function () {
    // Route::group(['middleware' => ['tenant']], function () {
        Route::post('/company/create-dispatcher', [DispatcherController::class, 'createDispatcher']);
    // });
});

Route::get('/tenant/me', function (Illuminate\Http\Request $request) {
    try {
        $tenant = auth('tenant')->setToken($request->bearerToken())->user();
        return response()->json(['tenant' => $tenant]);
    } catch (\Exception $e) {
        return response()->json(['message' => 'Unauthenticated', 'error' => $e->getMessage()], 401);
    }
});
