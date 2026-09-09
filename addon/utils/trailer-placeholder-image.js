import { PLACEHOLDER_IMAGES, getPlaceholderImage } from './placeholder-images';

/**
 * Kept for callers that import the trailer placeholder directly; the full set lives in
 * `placeholder-images`.
 */
export const TRAILER_PLACEHOLDER_IMAGE = PLACEHOLDER_IMAGES.trailer;

export default function getTrailerPlaceholderImage() {
    return getPlaceholderImage('trailer');
}
