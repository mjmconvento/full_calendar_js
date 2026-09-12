/*
 * Entry point compiled by AssetMapper (see importmap.php).
 *
 * Bootstrap 5 is pinned as an importmap dependency and vendored under
 * assets/vendor/. FullCalendar 7 is loaded from the self-contained global
 * bundles in assets/fullcalendar/ (see templates/calendar/index.html.twig):
 * its published ES modules expect a bundler to dedupe their Preact runtime,
 * which a browser-native importmap cannot do. Either way there is no Node
 * build step in this project.
 */
import 'bootstrap/dist/css/bootstrap.min.css';
import './styles/app.css';

import { bootBookingCalendar } from './calendar/booking-calendar.js';
import { bootDayPage } from './calendar/day-page.js';
import { bootPagedResults } from './paged-results.js';

const mount = document.getElementById('calendar');
if (mount) {
    bootBookingCalendar(mount);
}

// Only pages with a `data-confirm` form (the day page) have the dialog this
// looks for; everywhere else it returns immediately.
bootDayPage();

// The two paged cards: the customer directory (with its as-you-type filter)
// and the dashboard's list of every booking.
const customerResults = document.getElementById('customer-results');
if (customerResults) {
    bootPagedResults(customerResults, {
        searchInput: document.getElementById('customer-search'),
        clearLink: document.querySelector('[data-customer-clear]'),
    });
}

const bookingsResults = document.getElementById('bookings-results');
if (bookingsResults) {
    bootPagedResults(bookingsResults);
}
