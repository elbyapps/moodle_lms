/**
 * Collapsible menu item component for hierarchical navigation.
 *
 * @package    local_reblibrary
 * @copyright  2025 Rwanda Education Board
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import { h } from "preact";
import { useState, useEffect } from "preact/hooks";

export interface CollapsibleMenuItemProps {
    label: string;
    icon?: string;
    url?: string;
    active?: boolean;
    isExpanded?: boolean;
    onToggle?: () => void;
    children?: preact.ComponentChildren;
    level?: number; // Indentation level: 0 (root), 1 (level), 2 (sublevel), 3 (class)
}

export default function CollapsibleMenuItem({
    label,
    icon,
    url,
    active = false,
    isExpanded = false,
    onToggle,
    children,
    level = 0
}: CollapsibleMenuItemProps) {
    const hasChildren = !!children;
    // Updated indentation: 0 (root), 1 (unused), 2 (sublevel), 3 (class)
    const indentationClass = level === 0 ? 'pl-3' : level === 2 ? 'pl-8' : level === 3 ? 'pl-12' : 'pl-4';

    // Text size based on level: sublevel (normal), class (smaller)
    const textSizeClass = level === 3 ? 'text-xs' : 'text-sm';

    // Font weight: sublevel (medium), class (normal)
    const fontWeightClass = level === 2 ? 'font-medium' : level === 3 ? 'font-normal' : '';

    const handleClick = (e: MouseEvent) => {
        if (hasChildren && onToggle) {
            e.preventDefault();
            onToggle();
        }
    };

    const ItemContent = () => (
        <div className="flex items-center justify-between w-full">
            <div className="flex items-center flex-1 min-w-0">
                {icon && <i className={`${icon} w-5 mr-3 flex-shrink-0`}></i>}
                <span className="truncate">{label}</span>
            </div>
            {hasChildren && (
                <i className={`fa fa-chevron-${isExpanded ? 'down' : 'right'} text-xs ml-2 flex-shrink-0 transition-transform`}></i>
            )}
        </div>
    );

    return (
        <div>
            {url && !hasChildren ? (
                <a
                    href={url}
                    className={`flex items-center ${indentationClass} ${textSizeClass} ${fontWeightClass} py-2 rounded transition-colors ${
                        active
                            ? "bg-reb-blue text-white"
                            : "text-gray-700 hover:bg-gray-200"
                    }`}
                >
                    <ItemContent />
                </a>
            ) : (
                <button
                    onClick={handleClick}
                    className={`flex items-center ${indentationClass} ${textSizeClass} ${fontWeightClass} py-2 rounded transition-colors w-full text-left ${
                        active
                            ? "bg-reb-blue text-white"
                            : "text-gray-700 hover:bg-gray-200"
                    }`}
                    type="button"
                >
                    <ItemContent />
                </button>
            )}
            {hasChildren && isExpanded && (
                <div className="mt-1 space-y-1">
                    {children}
                </div>
            )}
        </div>
    );
}
