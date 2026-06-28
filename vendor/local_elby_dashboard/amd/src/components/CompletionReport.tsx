// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Completion Report component for RTB Dashboard.
 *
 * @module     local_rtbdashboard/components/CompletionReport
 * @copyright  2025 Rwanda TVET Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { useState } from 'preact/hooks';

// Parent category structure
interface ParentCategory {
    id: number;
    name: string;
}

// Unit data structure
interface UnitData {
    average: number | null;
    completionRate: string;
}

// Category row data structure
interface CategoryData {
    id: number;
    name: string;
    parentId: number;
    participants: number;
    units: UnitData[];
}

// Dummy parent categories
const parentCategories: ParentCategory[] = [
    { id: 0, name: 'All Categories' },
    { id: 1, name: 'Kigali City' },
    { id: 2, name: 'Eastern Province' },
    { id: 3, name: 'Western Province' },
    { id: 4, name: 'Northern Province' },
    { id: 5, name: 'Southern Province' },
];

// Dummy data matching the image structure
const dummyData: CategoryData[] = [
    {
        id: 1,
        name: 'Bicumbi',
        parentId: 2,
        participants: 47,
        units: [
            { average: 9.2, completionRate: '95.7%' },
            { average: 3.67, completionRate: '4.3%' },
            { average: 2.82, completionRate: '4.3%' },
            { average: 5, completionRate: '2.1%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 2,
        name: 'Cyahinda',
        parentId: 5,
        participants: 101,
        units: [
            { average: 8.8, completionRate: '55.4%' },
            { average: 7.52, completionRate: '10.9%' },
            { average: null, completionRate: '0.0%' },
            { average: 9.31, completionRate: '12.9%' },
            { average: 7.81, completionRate: '5.0%' },
            { average: null, completionRate: '0.0%' },
            { average: 6.52, completionRate: '4.0%' },
            { average: 9.02, completionRate: '6.9%' },
        ],
    },
    {
        id: 3,
        name: 'De La Salle Byumba',
        parentId: 4,
        participants: 90,
        units: [
            { average: 6.7, completionRate: '93.3%' },
            { average: 6.23, completionRate: '62.2%' },
            { average: 5.3, completionRate: '25.6%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: 5.88, completionRate: '1.1%' },
            { average: 2.14, completionRate: '1.1%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 4,
        name: 'Gacuba II',
        parentId: 3,
        participants: 86,
        units: [
            { average: 6.6, completionRate: '84.9%' },
            { average: 7.32, completionRate: '43.0%' },
            { average: 0, completionRate: '1.2%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 5,
        name: 'Kabarore',
        parentId: 4,
        participants: 93,
        units: [
            { average: 9.4, completionRate: '96.8%' },
            { average: 5.82, completionRate: '11.8%' },
            { average: 8.75, completionRate: '1.1%' },
            { average: 9.09, completionRate: '1.1%' },
            { average: 0.38, completionRate: '1.1%' },
            { average: null, completionRate: '0.0%' },
            { average: 6.43, completionRate: '1.1%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 6,
        name: 'Kirambo',
        parentId: 3,
        participants: 97,
        units: [
            { average: 5.3, completionRate: '3.1%' },
            { average: null, completionRate: '0.0%' },
            { average: 3.75, completionRate: '1.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 7,
        name: 'Matimba',
        parentId: 2,
        participants: 44,
        units: [
            { average: 9.3, completionRate: '93.2%' },
            { average: 4.25, completionRate: '18.2%' },
            { average: 4.38, completionRate: '6.8%' },
            { average: 5, completionRate: '2.3%' },
            { average: 10, completionRate: '2.3%' },
            { average: 7.65, completionRate: '2.3%' },
            { average: 7.14, completionRate: '2.3%' },
            { average: 8.75, completionRate: '2.3%' },
        ],
    },
    {
        id: 8,
        name: 'Mbuga',
        parentId: 1,
        participants: 93,
        units: [
            { average: 8.5, completionRate: '92.5%' },
            { average: 3.33, completionRate: '2.2%' },
            { average: 10, completionRate: '1.1%' },
            { average: 9.55, completionRate: '1.1%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 9,
        name: 'Muhanga',
        parentId: 5,
        participants: 50,
        units: [
            { average: 7.3, completionRate: '88.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 10,
        name: 'Muramba',
        parentId: 3,
        participants: 31,
        units: [
            { average: 9.7, completionRate: '100.0%' },
            { average: 9.29, completionRate: '93.5%' },
            { average: 7.82, completionRate: '6.5%' },
            { average: 7.28, completionRate: '6.5%' },
            { average: 8.75, completionRate: '6.5%' },
            { average: 7.84, completionRate: '9.7%' },
            { average: 7.68, completionRate: '6.5%' },
            { average: 7.71, completionRate: '9.7%' },
        ],
    },
    {
        id: 11,
        name: 'Mururu',
        parentId: 3,
        participants: 63,
        units: [
            { average: 9.3, completionRate: '92.1%' },
            { average: 3.33, completionRate: '1.6%' },
            { average: null, completionRate: '0.0%' },
            { average: 7.12, completionRate: '4.8%' },
            { average: 9.62, completionRate: '3.2%' },
            { average: 8.82, completionRate: '4.8%' },
            { average: 4.47, completionRate: '3.2%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 12,
        name: 'Mwezi',
        parentId: 4,
        participants: 58,
        units: [
            { average: 9.9, completionRate: '100.0%' },
            { average: 9, completionRate: '89.7%' },
            { average: 0, completionRate: '1.7%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: 1.25, completionRate: '1.7%' },
        ],
    },
    {
        id: 13,
        name: 'Nyamata',
        parentId: 2,
        participants: 89,
        units: [
            { average: 7.7, completionRate: '10.1%' },
            { average: 1.78, completionRate: '3.4%' },
            { average: 8.78, completionRate: '53.9%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
    {
        id: 14,
        name: 'Rubengera',
        parentId: 3,
        participants: 51,
        units: [
            { average: 6.4, completionRate: '100.0%' },
            { average: 6.11, completionRate: '98.0%' },
            { average: 3.44, completionRate: '3.9%' },
            { average: 5.15, completionRate: '2.0%' },
            { average: 3.27, completionRate: '2.0%' },
            { average: 2.94, completionRate: '2.0%' },
            { average: 3.93, completionRate: '2.0%' },
            { average: 1.88, completionRate: '2.0%' },
        ],
    },
    {
        id: 15,
        name: 'Save',
        parentId: 5,
        participants: 90,
        units: [
            { average: 8.1, completionRate: '94.4%' },
            { average: 5.84, completionRate: '18.9%' },
            { average: 3.75, completionRate: '1.1%' },
            { average: 4.09, completionRate: '1.1%' },
            { average: 3.65, completionRate: '1.1%' },
            { average: 5.88, completionRate: '2.2%' },
            { average: 6.31, completionRate: '3.3%' },
            { average: 6.88, completionRate: '1.1%' },
        ],
    },
    {
        id: 16,
        name: 'Zaza',
        parentId: 2,
        participants: 114,
        units: [
            { average: 8.4, completionRate: '91.2%' },
            { average: 5.13, completionRate: '20.2%' },
            { average: 4.38, completionRate: '0.9%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
            { average: null, completionRate: '0.0%' },
        ],
    },
];

// Grand total row
const grandTotal = {
    name: 'Grand total',
    participants: 1197,
    units: [
        { average: 8.3, completionRate: '76.7%' },
        { average: 6.9, completionRate: '25.2%' },
        { average: 7.07, completionRate: '7.3%' },
        { average: 8.12, completionRate: '2.0%' },
        { average: 7.16, completionRate: '1.1%' },
        { average: 7.11, completionRate: '0.9%' },
        { average: 5.93, completionRate: '1.3%' },
        { average: 7.5, completionRate: '1.2%' },
    ],
};

const unitLabels = ['U1', 'U2', 'U3', 'U4', 'U5', 'U6', 'U7', 'U8'];

export default function CompletionReport() {
    const [selectedParentId, setSelectedParentId] = useState(0);

    // Filter data based on selected parent category
    const filteredData = selectedParentId === 0
        ? dummyData
        : dummyData.filter(row => row.parentId === selectedParentId);

    // Calculate grand total for filtered data
    const calculateGrandTotal = () => {
        if (filteredData.length === 0) {
            return grandTotal;
        }

        const totalParticipants = filteredData.reduce((sum, row) => sum + row.participants, 0);

        // Calculate average for each unit
        const unitTotals = unitLabels.map((_, unitIndex) => {
            const validRows = filteredData.filter(row => row.units[unitIndex].average !== null);
            if (validRows.length === 0) {
                return { average: null, completionRate: '0.0%' };
            }

            const avgSum = validRows.reduce((sum, row) => sum + (row.units[unitIndex].average || 0), 0);
            const crSum = filteredData.reduce((sum, row) => {
                const cr = parseFloat(row.units[unitIndex].completionRate);
                return sum + (isNaN(cr) ? 0 : cr);
            }, 0);

            return {
                average: Math.round((avgSum / validRows.length) * 100) / 100,
                completionRate: `${(crSum / filteredData.length).toFixed(1)}%`,
            };
        });

        return {
            name: 'Grand total',
            participants: totalParticipants,
            units: unitTotals,
        };
    };

    const currentGrandTotal = selectedParentId === 0 ? grandTotal : calculateGrandTotal();

    return (
        <div className="p-2 sm:p-4 lg:p-6 bg-white min-h-screen">
            {/* Page Header */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-gray-800">Overview</h1>
            </div>

            {/* Category Filter */}
            <div className="mb-4">
                <label htmlFor="parent-category" className="block text-sm font-medium text-gray-700 mb-1">
                    Parent Category
                </label>
                <select
                    id="parent-category"
                    value={selectedParentId}
                    onChange={(e) => setSelectedParentId(Number((e.target as HTMLSelectElement).value))}
                    className="border border-gray-300 rounded-lg px-3 py-2 min-w-[200px] focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                >
                    {parentCategories.map((cat) => (
                        <option key={cat.id} value={cat.id}>
                            {cat.name}
                        </option>
                    ))}
                </select>
            </div>

            {/* Table */}
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-gray-200">
                            <th className="py-3 px-2 text-left font-medium text-gray-600 w-8"></th>
                            <th className="py-3 px-2 text-left font-medium text-gray-600 min-w-[140px]">
                                Category <span className="text-gray-400">▲</span>
                            </th>
                            <th className="py-3 px-2 text-center font-medium text-gray-600"># of Stds</th>
                            {unitLabels.map((unit) => (
                                <>
                                    <th key={`${unit}-ave`} className="py-3 px-2 text-center font-medium text-gray-600">
                                        {unit} Ave.
                                    </th>
                                    <th key={`${unit}-cr`} className="py-3 px-2 text-center font-medium text-gray-600">
                                        {unit} CR
                                    </th>
                                </>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {filteredData.map((row, index) => (
                            <tr
                                key={row.id}
                                className={index % 2 === 0 ? 'bg-sky-50' : 'bg-white'}
                            >
                                <td className="py-2 px-2 text-gray-500">{index + 1}.</td>
                                <td className="py-2 px-2 text-gray-800">{row.name}</td>
                                <td className="py-2 px-2 text-center text-gray-600">{row.participants}</td>
                                {row.units.map((unit, unitIndex) => (
                                    <>
                                        <td key={`ave-${unitIndex}`} className="py-2 px-2 text-center text-gray-600">
                                            {unit.average !== null ? unit.average : '-'}
                                        </td>
                                        <td key={`cr-${unitIndex}`} className="py-2 px-2 text-center text-gray-600">
                                            {unit.completionRate}
                                        </td>
                                    </>
                                ))}
                            </tr>
                        ))}
                        {/* Grand Total Row */}
                        <tr className="bg-white border-t border-gray-300">
                            <td className="py-2 px-2"></td>
                            <td className="py-2 px-2 font-semibold text-gray-800">{currentGrandTotal.name}</td>
                            <td className="py-2 px-2 text-center font-semibold text-gray-800">
                                {currentGrandTotal.participants >= 1000
                                    ? `${(currentGrandTotal.participants / 1000).toFixed(1)}K`
                                    : currentGrandTotal.participants}
                            </td>
                            {currentGrandTotal.units.map((unit, unitIndex) => (
                                <>
                                    <td key={`total-ave-${unitIndex}`} className="py-2 px-2 text-center font-semibold text-gray-800">
                                        {unit.average !== null ? unit.average : '-'}
                                    </td>
                                    <td key={`total-cr-${unitIndex}`} className="py-2 px-2 text-center font-semibold text-gray-800">
                                        {unit.completionRate}
                                    </td>
                                </>
                            ))}
                        </tr>
                    </tbody>
                </table>
            </div>

            {filteredData.length === 0 && (
                <div className="text-center py-8 text-gray-500">
                    No categories found for the selected parent category.
                </div>
            )}
        </div>
    );
}
