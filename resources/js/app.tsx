import { createInertiaApp } from '@inertiajs/react';
import Welcome from './pages/welcome';

export default createInertiaApp({
    resolve: () => Welcome,
    title: (title) => (title ? `${title} - findward` : 'findward'),
    strictMode: true,
    layout: () => null,
    progress: { color: '#4B5563' },
});
