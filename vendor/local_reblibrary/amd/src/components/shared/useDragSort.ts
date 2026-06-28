/**
 * Lightweight HTML5 drag-and-drop reorder hook for table rows.
 *
 * Used by the Education Structure tabs to let admins drag rows up/down to
 * persist a `sortorder` field. Stays dependency-free: relies on the browser's
 * native `dragstart`/`dragover`/`drop` events.
 *
 * Optionally accepts a `getGroupKey(item)` to scope DnD to a parent group
 * (e.g. classes within the same sublevel). Drops across groups are rejected.
 *
 * @module local_reblibrary/components/shared/useDragSort
 */

import { useState } from "preact/hooks";

export interface UseDragSortOptions<T> {
    /** Optional grouping key — drops are only allowed within the same group. */
    getGroupKey?: (item: T) => string | number;
    /** Called with the newly-ordered list after a successful drop. */
    onCommit: (newOrder: T[]) => void;
}

export interface DragHandlers {
    /** Wire to the `<tr>` (or whatever element is being dragged). */
    rowProps: (index: number) => {
        draggable: boolean;
        onDragOver: (e: DragEvent) => void;
        onDragLeave: (e: DragEvent) => void;
        onDrop: (e: DragEvent) => void;
        onDragEnd: (e: DragEvent) => void;
        className: string;
    };
    /** Wire to the drag-handle element (the grip icon cell). */
    handleProps: (index: number) => {
        onDragStart: (e: DragEvent) => void;
        onMouseDown: (e: MouseEvent) => void;
        onMouseUp: (e: MouseEvent) => void;
        className: string;
    };
}

/**
 * Returns props you spread onto a row and its drag handle.
 *
 * Pattern:
 * ```tsx
 * const dnd = useDragSort(items, { onCommit: persist, getGroupKey: x => x.parent_id });
 * items.map((item, i) => (
 *   <tr {...dnd.rowProps(i)} key={item.id}>
 *     <td {...dnd.handleProps(i)}><i class="fa fa-grip-vertical" /></td>
 *     ...
 *   </tr>
 * ))
 * ```
 */
export function useDragSort<T>(items: T[], opts: UseDragSortOptions<T>): DragHandlers {
    // Index of the row whose handle has been mousedown'd — that row (and only
    // that row) is `draggable` so clicks on Edit/Delete or other cells won't
    // start a drag.
    const [armedIndex, setArmedIndex] = useState<number | null>(null);
    // Index of the row currently being dragged (set on dragstart).
    const [draggedIndex, setDraggedIndex] = useState<number | null>(null);
    // Index of the row currently under the dragged item (drop target).
    const [hoverIndex, setHoverIndex] = useState<number | null>(null);

    const sameGroup = (a: number, b: number): boolean => {
        if (!opts.getGroupKey) return true;
        return opts.getGroupKey(items[a]) === opts.getGroupKey(items[b]);
    };

    const reset = () => {
        setArmedIndex(null);
        setDraggedIndex(null);
        setHoverIndex(null);
    };

    return {
        rowProps: (index: number) => ({
            // Only the armed row is draggable; once dragging starts we keep it
            // draggable until dragend.
            draggable: armedIndex === index || draggedIndex === index,
            onDragOver: (e: DragEvent) => {
                if (draggedIndex === null || draggedIndex === index) return;
                if (!sameGroup(draggedIndex, index)) {
                    // Cross-group drop — refuse the operation.
                    if (e.dataTransfer) e.dataTransfer.dropEffect = "none";
                    return;
                }
                e.preventDefault(); // Required to allow drop.
                if (e.dataTransfer) e.dataTransfer.dropEffect = "move";
                if (hoverIndex !== index) setHoverIndex(index);
            },
            onDragLeave: (_e: DragEvent) => {
                // Clear hover only if we were the active target; cheap & safe.
                if (hoverIndex === index) setHoverIndex(null);
            },
            onDrop: (e: DragEvent) => {
                e.preventDefault();
                if (draggedIndex === null || draggedIndex === index) {
                    reset();
                    return;
                }
                if (!sameGroup(draggedIndex, index)) {
                    reset();
                    return;
                }
                const newOrder = items.slice();
                const [moved] = newOrder.splice(draggedIndex, 1);
                newOrder.splice(index, 0, moved);
                opts.onCommit(newOrder);
                reset();
            },
            onDragEnd: (_e: DragEvent) => {
                reset();
            },
            className: [
                draggedIndex === index ? "opacity-40" : "",
                hoverIndex === index && draggedIndex !== null && draggedIndex !== index
                    ? "ring-2 ring-reb-blue ring-inset"
                    : "",
            ].filter(Boolean).join(" "),
        }),
        handleProps: (index: number) => ({
            onDragStart: (e: DragEvent) => {
                setDraggedIndex(index);
                setHoverIndex(null);
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = "move";
                    // Some browsers require a payload to start a drag.
                    e.dataTransfer.setData("text/plain", String(index));
                }
            },
            onMouseDown: (_e: MouseEvent) => {
                // Arm this row so the browser will honor `draggable=true` on it.
                setArmedIndex(index);
            },
            onMouseUp: (_e: MouseEvent) => {
                // Mouseup before drag started — disarm so a stray click doesn't
                // leave the row in a draggable state.
                if (draggedIndex === null) setArmedIndex(null);
            },
            className: "cursor-grab active:cursor-grabbing text-gray-400 hover:text-gray-700 select-none",
        }),
    };
}
