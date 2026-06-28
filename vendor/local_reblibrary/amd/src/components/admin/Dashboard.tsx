import { h } from "preact";
import { useEffect } from "preact/hooks";
import StatsCard from "./StatsCard";
import { loadResources } from "../../services/resource-store";
import { statsSignal, loadingSignal, errorSignal } from "../../store";

interface DashboardProps {
    stats: {
        totalResources: number;
        totalAuthors: number;
        totalCategories: number;
        totalClasses: number;
    };
}

export default function Dashboard({ stats }: DashboardProps) {
    // Load resources on component mount
    useEffect(() => {
        loadResources();
    }, []);

    return (
        <section className="p-4 pt-14 lg:p-8">
            <h2 className="text-xl lg:text-2xl font-semibold text-gray-800 mb-4 lg:mb-6">Dashboard Overview</h2>

            {/* Error Message */}
            {errorSignal.value && (
                <div className="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                    <h5 className="text-lg font-semibold text-red-800 mb-2">Error</h5>
                    <p className="text-red-700">{errorSignal.value}</p>
                </div>
            )}

            {/* Stats Cards Grid */}
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-5 mb-6">
                <StatsCard
                    icon="fa fa-book"
                    number={statsSignal.value.totalResources}
                    label="Total Resources"
                />
                <StatsCard
                    icon="fa fa-user-edit"
                    number={statsSignal.value.totalAuthors}
                    label="Authors"
                />
                <StatsCard
                    icon="fa fa-tags"
                    number={statsSignal.value.totalCategories}
                    label="Categories"
                />
                <StatsCard
                    icon="fa fa-graduation-cap"
                    number={statsSignal.value.totalClasses}
                    label="Classes"
                />
            </div>

            {/* Loading Indicator */}
            {loadingSignal.value && (
                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                    <p className="text-gray-700">Loading resources...</p>
                </div>
            )}

            {/* Welcome Message */}
            <div className="bg-reb-blue-50 border border-reb-blue-200 rounded-lg p-4">
                <h5 className="text-lg font-semibold text-reb-blue-800 mb-2">
                    Welcome to REB Library Administration
                </h5>
                <p className="text-reb-blue-700">
                    Use the sidebar menu to manage education structure, resources, categories, and assignments.
                </p>
            </div>
        </section>
    );
}
