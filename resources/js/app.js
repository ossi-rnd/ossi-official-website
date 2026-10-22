import clockCapture from './clock-capture';
import customSelect from './custom-select';
import formatEmployeeNumber from './employee-number';
import formatGovernmentId from './government-id';
import idleTimeout from './idle-timeout';
import patrolScanner, { currentPosition } from './patrol-scanner';
import awayMap from './away-map';
import sitePicker from './site-picker';
import liveBell from './live-bell';
import registerFormLoading from './form-loading';
import registerCharts from './charts';
import registerErrorPages from './error-pages';

// Chart.js, Leaflet and jsQR are loaded only by the components that need them
// (charts.js, away-map.js, patrol-scanner.js), so every other page stays light.

// Spinner + no double submit on plain (non-Livewire) forms: login, register…
registerFormLoading();

// Used inline by the employee number field (x-on:input).
window.formatEmployeeNumber = formatEmployeeNumber;

// Used inline by the SSS / PhilHealth / Pag-IBIG / TIN fields (x-on:input).
window.formatGovernmentId = formatGovernmentId;

// Used inline by "Leaving post" / "Back at post" (one GPS fix each).
window.currentPosition = currentPosition;

// Livewire ships Alpine; register our components before it starts.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('idleTimeout', idleTimeout);
    window.Alpine.data('customSelect', customSelect);
    window.Alpine.data('clockCapture', clockCapture);
    window.Alpine.data('patrolScanner', patrolScanner);
    window.Alpine.data('awayMap', awayMap);
    window.Alpine.data('sitePicker', sitePicker);
    window.Alpine.data('liveBell', liveBell);
    registerCharts(window.Alpine);
});

// A failed Livewire request shows our error page full screen, not in Livewire's pop-up.
document.addEventListener('livewire:init', () => registerErrorPages(window.Livewire));
