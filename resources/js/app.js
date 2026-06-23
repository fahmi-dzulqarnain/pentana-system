import './bootstrap';

// Spatie's passkey components ship NO JS; their inline scripts call the global
// startRegistration / startAuthentication. Expose the @simplewebauthn/browser
// API as window globals so the WebAuthn ceremony resolves in the browser.
import { startRegistration, startAuthentication } from '@simplewebauthn/browser';

window.startRegistration = startRegistration;
window.startAuthentication = startAuthentication;
