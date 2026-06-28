import { h } from "preact";

interface StatsCardProps {
    icon: string;
    number: number;
    label: string;
}

export default function StatsCard({ icon, number, label }: StatsCardProps) {
    return (
        <div className="bg-white rounded-lg shadow-md hover:shadow-lg transition-shadow p-6 text-center">
            <div className="text-4xl text-reb-blue mb-4">
                <i className={icon}></i>
            </div>
            <h3 className="text-3xl font-bold text-reb-blue mb-2">{number}</h3>
            <p className="text-sm text-gray-600">{label}</p>
        </div>
    );
}
