import { h } from "preact";
import Sidebar from "./components/shared/Sidebar";
import Dashboard from "./components/admin/Dashboard";
import { statsSignal } from "./store";
import { getAdminMenuItems } from "./config/admin-menu";
import { getLibraryMenuItems } from "./config/library-menu";
import type { EducationLevel, EducationSublevel, EducationClass } from "./types";

interface AppProps {
	levels?: EducationLevel[];
	sublevels?: EducationSublevel[];
	classes?: EducationClass[];
}

export default function App({ levels = [], sublevels = [], classes = [] }: AppProps) {
	// Get menu items from configuration
	const adminMenuItems = getAdminMenuItems('dashboard');
	const libraryMenuItems = getLibraryMenuItems();

	return (
		<div className="flex min-h-screen bg-white">
			<Sidebar
				adminMenuItems={adminMenuItems}
				libraryMenuItems={libraryMenuItems}
				levels={levels}
				sublevels={sublevels}
				classes={classes}
			/>
			<main className="flex-1 overflow-y-auto min-w-0">
				<Dashboard stats={statsSignal.value} />
			</main>
		</div>
	);
}
