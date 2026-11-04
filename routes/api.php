<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CollectController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationMemberController;
use App\Http\Controllers\Api\V1\OrganizationInvitationController;
use App\Http\Controllers\Api\V1\ParticipantController;
use App\Http\Controllers\Api\V1\PublicStudyController;
use App\Http\Controllers\Api\V1\QuestionnaireController;
use App\Http\Controllers\Api\V1\StudyAssignmentController;
use App\Http\Controllers\Api\V1\StudyController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Middleware\EnsureEmailVerified;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('ping', fn () => response()->json(['status' => 'ok', 'app' => 'DOBI API', 'time' => now()]));

    // ---- Authentification ----
    Route::prefix('auth')->group(function () {
        Route::middleware('throttle:auth')->group(function () {
            Route::post('register', [AuthController::class, 'register']);
            Route::post('login', [AuthController::class, 'login']);
            Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
            Route::post('reset-password', [AuthController::class, 'resetPassword']);
        });
        Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');
        Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:verification');
        Route::get('email/change/{id}', [AuthController::class, 'confirmEmailChange'])->name('email.change.confirm');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    // ---- Public (sans compte) ----
    Route::prefix('public')->group(function () {
        Route::middleware('throttle:public-read')->group(function () {
            Route::get('organizations/{slug}', [PublicStudyController::class, 'organization']);
            Route::get('studies/{slug}', [PublicStudyController::class, 'show']);
        });
        Route::post('studies/{slug}/responses', [PublicStudyController::class, 'submit'])->middleware('throttle:public-submit');
    });

    // ---- Espace connecté ----
    Route::middleware(['auth:sanctum', EnsureEmailVerified::class, 'throttle:api'])->group(function () {
        Route::post('invitations/{token}/accept', [OrganizationInvitationController::class, 'accept']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [AuthController::class, 'updateMe']);
        Route::post('me/logout-others', [AuthController::class, 'logoutOthers']);
        Route::put('me/email', [AuthController::class, 'requestEmailChange'])->middleware('throttle:verification');
        Route::delete('me', [AuthController::class, 'destroyMe']);

        // Organisations et membres
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::post('organizations', [OrganizationController::class, 'store']);
        Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
        Route::put('organizations/{organization}', [OrganizationController::class, 'update']);
        Route::post('organizations/{organization}/leave', [OrganizationController::class, 'leave']);
        Route::post('organizations/{organization}/transfer', [OrganizationController::class, 'transfer']);

        Route::get('organizations/{organization}/dashboard', [DashboardController::class, 'show']);
        Route::get('organizations/{organization}/members', [OrganizationMemberController::class, 'index']);
        Route::get('organizations/{organization}/invitations', [OrganizationInvitationController::class, 'index']);
        Route::post('organizations/{organization}/invitations', [OrganizationInvitationController::class, 'store']);
        Route::delete('organizations/{organization}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy']);
        Route::put('organizations/{organization}/members/{user}', [OrganizationMemberController::class, 'update']);
        Route::delete('organizations/{organization}/members/{user}', [OrganizationMemberController::class, 'destroy']);

        // Études
        Route::get('organizations/{organization}/studies', [StudyController::class, 'index']);
        Route::post('organizations/{organization}/studies', [StudyController::class, 'store']);
        Route::get('studies/{study}', [StudyController::class, 'show']);
        Route::put('studies/{study}', [StudyController::class, 'update']);
        Route::get('studies/{study}/history', [StudyController::class, 'history']);
        Route::post('studies/{study}/duplicate', [StudyController::class, 'duplicate']);
        Route::post('studies/{study}/{action}', [StudyController::class, 'transition'])
            ->whereIn('action', ['publish', 'resume', 'suspend', 'close', 'archive', 'restore']);

        // Constructeur de questionnaire
        Route::get('studies/{study}/questionnaire', [QuestionnaireController::class, 'show']);
        Route::post('studies/{study}/sections', [QuestionnaireController::class, 'storeSection']);
        Route::put('sections/{section}', [QuestionnaireController::class, 'updateSection']);
        Route::delete('sections/{section}', [QuestionnaireController::class, 'destroySection']);
        Route::post('studies/{study}/questions', [QuestionnaireController::class, 'storeQuestion']);
        Route::put('studies/{study}/layout', [QuestionnaireController::class, 'layout']);
        Route::post('studies/{study}/reorder', [QuestionnaireController::class, 'reorder']);
        Route::post('questions/{question}/duplicate', [QuestionnaireController::class, 'duplicateQuestion']);
        Route::put('questions/{question}', [QuestionnaireController::class, 'updateQuestion']);
        Route::delete('questions/{question}', [QuestionnaireController::class, 'destroyQuestion']);

        // Résultats
        Route::get('organizations/{organization}/participation', [ParticipantController::class, 'overview']);
        Route::get('studies/{study}/participants', [ParticipantController::class, 'index']);
        Route::delete('studies/{study}/participants/{uuid}', [ParticipantController::class, 'destroy']);
        Route::get('studies/{study}/analytics', [AnalyticsController::class, 'show']);

        // Modèles de formulaires
        Route::get('organizations/{organization}/templates', [TemplateController::class, 'index']);
        Route::post('organizations/{organization}/studies/from-template', [TemplateController::class, 'createStudy']);
        Route::post('studies/{study}/template', [TemplateController::class, 'store']);
        Route::delete('templates/{template}', [TemplateController::class, 'destroy']);

        // Collecte par les enquêteurs et suivi
        Route::get('collect/studies', [CollectController::class, 'studies']);
        Route::post('collect/studies/{slug}/responses', [PublicStudyController::class, 'collect']);
        Route::get('studies/{study}/collectors', [CollectController::class, 'collectors']);
        Route::get('studies/{study}/assignments', [StudyAssignmentController::class, 'index']);
        Route::put('studies/{study}/assignments', [StudyAssignmentController::class, 'update']);
        Route::get('studies/{study}/exports', [ExportController::class, 'index']);
    });
});
