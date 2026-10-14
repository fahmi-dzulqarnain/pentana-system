// WebAuthn globals for Filament panel pages.
//
// Filament panels bundle their own Alpine/Livewire and do NOT load the app's
// resources/js/app.js. Spatie's passkey components ship no JS of their own -
// their inline scripts call the bare globals startAuthentication (login page)
// and startRegistration (Passkeys register page). Without these defined, the
// in-browser ceremony throws "startAuthentication is not defined".
//
// This file is registered as a Filament asset (FilamentAsset::register in
// AppServiceProvider) so it loads on every panel page. It deliberately imports
// ONLY @simplewebauthn/browser - no bootstrap/axios, no Alpine, no Livewire -
// to avoid double-registering anything Filament already bundles.
import { startAuthentication, startRegistration } from '@simplewebauthn/browser';

window.startAuthentication = startAuthentication;
window.startRegistration = startRegistration;
