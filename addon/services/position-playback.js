import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task, timeout } from 'ember-concurrency';
import { debug } from '@ember/debug';
import { isArray } from '@ember/array';

/**
 * PositionPlayback Service
 *
 * Client-side service for replaying historical position data with full playback controls.
 * Unlike movement-tracker which uses socket connections for real-time tracking,
 * this service handles pre-loaded position data entirely on the client side.
 *
 * Features:
 * - Play/Pause/Stop controls
 * - Step forward/backward through positions
 * - Adjustable playback speed (can be changed during playback)
 * - Jump to specific position
 * - Progress callbacks
 * - Automatic marker animation with rotation
 * - Real-time replay: respects actual time intervals between positions
 */
export default class PositionPlaybackService extends Service {
    @tracked isPlaying = false;
    @tracked isPaused = false;
    @tracked currentIndex = 0;
    @tracked positions = [];
    @tracked speed = 1;
    @tracked marker = null;
    @tracked map = null;
    @tracked callback = null;

    /**
     * Initialize replay session with positions and marker
     *
     * @param {Object} options - Configuration options
     * @param {Object} options.subject - Model/subject being tracked (must have leafletLayer property)
     * @param {Object} options.leafletLayer - Optional manual leaflet layer instance (overrides subject.leafletLayer)
     * @param {Array} options.positions - Array of position objects to replay
     * @param {Number} options.speed - Initial playback speed multiplier (default: 1)
     * @param {Function} options.callback - Callback function called after each position update
     * @param {Object} options.map - Optional Leaflet map instance for auto-panning
     */
    initialize(options = {}) {
        const { subject, leafletLayer, positions = [], speed = 1, callback = null, map = null } = options;

        // Get marker from subject or manual layer
        this.marker = leafletLayer || subject?.leafletLayer || subject?._layer || subject?._marker;

        if (!this.marker) {
            debug('[PositionPlayback] Warning: No leaflet marker found. Marker must be provided or subject must have leafletLayer property.');
        }

        this.positions = positions;
        this.speed = speed;
        this.callback = callback;
        this.map = map;
        this.currentIndex = 0;
        this.isPlaying = false;
        this.isPaused = false;

        debug(`[PositionPlayback] Initialized with ${positions.length} positions at ${speed}x speed`);
    }

    /**
     * Start or resume playback
     */
    play() {
        if (this.positions.length === 0) {
            debug('[PositionPlayback] Cannot play: No positions loaded');
            return;
        }

        if (this.isPlaying) {
            debug('[PositionPlayback] Already playing');
            return;
        }

        // If we're at the end, restart from beginning
        if (this.currentIndex >= this.positions.length) {
            this.currentIndex = 0;
        }

        this.isPlaying = true;
        this.isPaused = false;

        debug(`[PositionPlayback] Starting playback from position ${this.currentIndex + 1}/${this.positions.length}`);

        this.playbackTask.perform();
    }

    /**
     * Pause playback (can be resumed)
     */
    pause() {
        if (!this.isPlaying) {
            debug('[PositionPlayback] Not playing');
            return;
        }

        this.isPlaying = false;
        this.isPaused = true;

        debug(`[PositionPlayback] Paused at position ${this.currentIndex + 1}/${this.positions.length}`);
    }

    /**
     * Stop playback and reset to beginning
     */
    stop() {
        this.isPlaying = false;
        this.isPaused = false;
        this.currentIndex = 0;

        debug('[PositionPlayback] Stopped and reset to beginning');
    }

    /**
     * Set playback speed (can be changed during playback)
     *
     * @param {Number} speed - Speed multiplier (e.g., 1 = normal, 2 = 2x speed, 0.5 = half speed)
     */
    setSpeed(speed) {
        this.speed = speed;
        debug(`[PositionPlayback] Speed changed to ${speed}x`);
    }

    /**
     * Step forward by N positions
     *
     * @param {Number} steps - Number of positions to step forward (default: 1)
     */
    stepForward(steps = 1) {
        const targetIndex = Math.min(this.currentIndex + steps, this.positions.length - 1);

        if (targetIndex === this.currentIndex) {
            debug('[PositionPlayback] Already at last position');
            return;
        }

        this.jumpToPosition(targetIndex);
        debug(`[PositionPlayback] Stepped forward ${steps} position(s) to ${targetIndex + 1}/${this.positions.length}`);
    }

    /**
     * Step backward by N positions
     *
     * @param {Number} steps - Number of positions to step backward (default: 1)
     */
    stepBackward(steps = 1) {
        const targetIndex = Math.max(this.currentIndex - steps, 0);

        if (targetIndex === this.currentIndex) {
            debug('[PositionPlayback] Already at first position');
            return;
        }

        this.jumpToPosition(targetIndex);
        debug(`[PositionPlayback] Stepped backward ${steps} position(s) to ${targetIndex + 1}/${this.positions.length}`);
    }

    /**
     * Jump to specific position index (no animation)
     *
     * @param {Number} index - Target position index (0-based)
     */
    jumpToPosition(index) {
        if (index < 0 || index >= this.positions.length) {
            debug(`[PositionPlayback] Invalid index: ${index}`);
            return;
        }

        const position = this.positions[index];
        this.currentIndex = index;

        if (!this.marker || !position) {
            return;
        }

        // Update marker position without animation
        const latLng = this.#getLatLngFromPosition(position);
        if (latLng) {
            // Update rotation if heading is available
            if (typeof this.marker.setRotationAngle === 'function' && Number.isFinite(position.heading) && position.heading !== -1) {
                this.marker.setRotationAngle(position.heading);
            }

            if (typeof this.marker.slideTo === 'function') {
                this.marker.slideTo(latLng, { duration: 100 });
            } else {
                this.marker.setLatLng(latLng);
                requestAnimationFrame(() => {
                    if (typeof this.marker.setRotationAngle === 'function' && Number.isFinite(position.heading) && position.heading !== -1) {
                        this.marker.setRotationAngle(position.heading);
                    }
                });
            }

            // Pan map to position if map is provided
            if (this.map) {
                this.map.panTo(latLng, { animate: true });
            }

            // Trigger callback
            this.#triggerCallback(position, index, { animated: false });
        }

        debug(`[PositionPlayback] Jumped to position ${index + 1}/${this.positions.length}`);
    }

    /**
     * Get current playback progress as percentage
     *
     * @returns {Number} Progress percentage (0-100)
     */
    getProgress() {
        if (this.positions.length === 0) {
            return 0;
        }
        return Math.round((this.currentIndex / this.positions.length) * 100);
    }

    /**
     * Get current position data
     *
     * @returns {Object|null} Current position object or null
     */
    getCurrentPosition() {
        return this.positions[this.currentIndex] || null;
    }

    /**
     * Reset replay state
     */
    reset() {
        this.stop();
        this.positions = [];
        this.marker = null;
        this.map = null;
        this.callback = null;
        this.speed = 1;

        debug('[PositionPlayback] Reset complete');
    }

    /**
     * Main playback task using ember-concurrency
     * Handles sequential position updates with timing based on real intervals
     */
    @task *playbackTask() {
        debug(`[PositionPlayback] Playback task started from position ${this.currentIndex}`);

        while (this.isPlaying && this.currentIndex < this.positions.length) {
            const position = this.positions[this.currentIndex];
            const nextPosition = this.positions[this.currentIndex + 1];

            if (!position) {
                debug(`[PositionPlayback] Invalid position at index ${this.currentIndex}`);
                this.currentIndex++;
                continue;
            }

            // Get marker (it might have been updated)
            const marker = this.marker;
            if (!marker || !marker._map) {
                debug('[PositionPlayback] Marker not available or not on map');
                this.currentIndex++;
                continue;
            }

            // Calculate next position
            const latLng = this.#getLatLngFromPosition(position);
            if (!latLng) {
                debug(`[PositionPlayback] Invalid coordinates for position ${this.currentIndex}`);
                this.currentIndex++;
                continue;
            }

            // Calculate animation duration based on distance and speed
            const animationDuration = this.#calculateAnimationDuration(marker, latLng, position);

            try {
                // Apply rotation if heading is valid
                if (typeof marker.setRotationAngle === 'function' && Number.isFinite(position.heading) && position.heading !== -1) {
                    marker.setRotationAngle(position.heading);
                }

                // Move marker with animation
                if (typeof marker.slideTo === 'function') {
                    marker.slideTo(latLng, { duration: animationDuration });
                } else {
                    marker.setLatLng(latLng);
                }

                // Pan map to follow marker if map is provided
                if (this.map) {
                    const targetLatLng = marker._slideToLatLng ?? marker.getLatLng();
                    this.map.panTo(targetLatLng, { animate: true });
                }

                // Trigger callback
                this.#triggerCallback(position, this.currentIndex, { duration: animationDuration, animated: true });

                // Wait for animation to complete
                yield timeout(animationDuration + 50);

                // Calculate delay until next position based on real-time interval
                if (nextPosition) {
                    const delayUntilNext = this.#calculateDelayToNextPosition(position, nextPosition);

                    if (delayUntilNext > 0) {
                        debug(`[PositionPlayback] Waiting ${delayUntilNext}ms until next position (${this.speed}x speed)`);
                        yield timeout(delayUntilNext);
                    }
                }
            } catch (err) {
                debug(`[PositionPlayback] Error processing position ${this.currentIndex}: ${err.message}`);
            }

            // Move to next position
            this.currentIndex++;
        }

        // Playback complete
        if (this.currentIndex >= this.positions.length) {
            this.isPlaying = false;
            this.isPaused = false;
            debug('[PositionPlayback] Playback complete');

            // Trigger completion callback
            if (typeof this.callback === 'function') {
                this.callback({ type: 'complete', totalPositions: this.positions.length });
            }
        }
    }

    /**
     * Calculate delay to next position based on real-time interval
     *
     * @private
     * @param {Object} currentPosition - Current position object
     * @param {Object} nextPosition - Next position object
     * @returns {Number} Delay in milliseconds (adjusted by speed multiplier)
     */
    #calculateDelayToNextPosition(currentPosition, nextPosition) {
        // Try to get timestamps from positions
        const currentTime = this.#getTimestamp(currentPosition);
        const nextTime = this.#getTimestamp(nextPosition);

        if (!currentTime || !nextTime) {
            // No timestamp data, use default delay
            debug('[PositionPlayback] No timestamp data, using default delay');
            return 1000 / this.speed; // 1 second default, adjusted by speed
        }

        // Calculate real-time interval in milliseconds
        const realTimeInterval = nextTime - currentTime;

        if (realTimeInterval <= 0) {
            // Invalid interval, use minimum delay
            return 100 / this.speed;
        }

        // Apply speed multiplier (higher speed = shorter delay)
        const adjustedDelay = realTimeInterval / this.speed;

        // Clamp between reasonable bounds (50ms to 60 seconds)
        // At high speeds, we don't want delays too short
        // At low speeds, we cap at 60 seconds to prevent extremely long waits
        return Math.max(50, Math.min(adjustedDelay, 60000));
    }

    /**
     * Get timestamp from position object
     * Handles multiple timestamp field formats
     *
     * @private
     * @param {Object} position - Position object
     * @returns {Number|null} Timestamp in milliseconds or null
     */
    #getTimestamp(position) {
        // Try different timestamp fields
        const timestampFields = ['created_at', 'timestamp', 'recorded_at', 'time', 'datetime'];

        for (const field of timestampFields) {
            const value = position[field];
            if (value) {
                // Try to parse as date
                const timestamp = new Date(value).getTime();
                if (Number.isFinite(timestamp)) {
                    return timestamp;
                }
            }
        }

        return null;
    }

    /**
     * Extract lat/lng from position object
     * Handles multiple position data formats
     *
     * @private
     * @param {Object} position - Position object
     * @returns {Array|null} [lat, lng] or null if invalid
     */
    #getLatLngFromPosition(position) {
        // Direct latitude/longitude properties
        if (Number.isFinite(position.latitude) && Number.isFinite(position.longitude)) {
            return [position.latitude, position.longitude];
        }

        // GeoJSON format in location.coordinates [lng, lat]
        if (position.location?.coordinates && isArray(position.location.coordinates)) {
            const [lng, lat] = position.location.coordinates;
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                return [lat, lng];
            }
        }

        // Coordinates array [lat, lng]
        if (position.coordinates && isArray(position.coordinates)) {
            const [lat, lng] = position.coordinates;
            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                return [lat, lng];
            }
        }

        return null;
    }

    /**
     * Calculate animation duration based on distance and speed
     * This is for the marker movement animation, separate from the delay between positions
     *
     * @private
     * @param {Object} marker - Leaflet marker
     * @param {Array} nextLatLng - Target [lat, lng]
     * @param {Object} position - Position object with optional speed data
     * @returns {Number} Duration in milliseconds
     */
    #calculateAnimationDuration(marker, nextLatLng, position) {
        const map = marker._map;
        const prev = marker.getLatLng();
        const meters = map ? map.distance(prev, nextLatLng) : prev.distanceTo(nextLatLng);

        // Get speed from position data (assume m/s)
        let mps = Number.isFinite(position.speed) && position.speed > 0 ? position.speed : null;

        // If speed is in km/h, convert to m/s
        if (mps && position.speed_unit === 'kmh') {
            mps = mps / 3.6;
        }

        // Calculate base duration for animation
        let baseDuration = mps ? (meters / mps) * 1000 : 500;

        // For animation, we want it relatively quick regardless of playback speed
        // The playback speed affects the delay between positions, not the animation speed
        // Clamp between 100ms and 1000ms for smooth animation
        const duration = Math.max(100, Math.min(baseDuration, 1000));

        return duration;
    }

    /**
     * Trigger callback with position data
     *
     * @private
     * @param {Object} position - Position object
     * @param {Number} index - Current position index
     * @param {Object} metadata - Additional metadata
     */
    #triggerCallback(position, index, metadata = {}) {
        if (typeof this.callback === 'function') {
            this.callback({
                type: 'position',
                position,
                index,
                totalPositions: this.positions.length,
                progress: this.getProgress(),
                ...metadata,
            });
        }
    }
}
