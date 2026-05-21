import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './styles/admin.css';

const mount = document.getElementById('hof-admin-root');
if (mount) {
    createRoot(mount).render(<App bootstrap={window.hofAdmin || {}} />);
}
