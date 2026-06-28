import { h } from "preact";
import { useEffect, useState } from "preact/hooks";

interface ToastProps {
    message: string;
    type: "success" | "error";
    onClose: () => void;
    duration?: number;
}

export default function Toast({ message, type, onClose, duration = 5000 }: ToastProps) {
    const [isVisible, setIsVisible] = useState(false);

    useEffect(() => {
        // Trigger animation on mount
        setTimeout(() => setIsVisible(true), 10);

        // Auto-close after duration
        const timer = setTimeout(() => {
            setIsVisible(false);
            setTimeout(onClose, 300); // Wait for fade-out animation
        }, duration);

        return () => clearTimeout(timer);
    }, [duration, onClose]);

    const bgColor = type === "success" ? "bg-green-600" : "bg-red-600";
    const icon = type === "success" ? "fa-check-circle" : "fa-exclamation-circle";

    return (
        <div
            className={`fixed bottom-4 right-4 z-50 transition-all duration-300 transform ${
                isVisible ? "translate-y-0 opacity-100" : "translate-y-2 opacity-0"
            }`}
            style={{ maxWidth: "400px" }}
        >
            <div className={`${bgColor} text-white rounded-lg shadow-lg p-4 flex items-start space-x-3`}>
                <i className={`fa ${icon} text-xl flex-shrink-0 mt-0.5`}></i>
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-medium break-words">{message}</p>
                </div>
                <button
                    onClick={() => {
                        setIsVisible(false);
                        setTimeout(onClose, 300);
                    }}
                    className="flex-shrink-0 text-white hover:text-gray-200 transition-colors"
                    aria-label="Close"
                >
                    <i className="fa fa-times"></i>
                </button>
            </div>
        </div>
    );
}
