import '@testing-library/jest-dom/vitest';

import { configure } from '@testing-library/react';

// The admin area loads as its own chunk; give findBy* queries time on a busy machine.
configure({ asyncUtilTimeout: 5000 });
