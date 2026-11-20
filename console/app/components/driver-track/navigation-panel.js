import Component from '@glimmer/component';
import { action } from '@ember/object';
import { maneuverToIcon, formatDistance, formatDuration } from '../../utils/osrm-navigation';

export default class DriverTrackNavigationPanelComponent extends Component {
    get route() {
        return this.args.route;
    }

    get currentStep() {
        if (!this.route || !this.route.steps) return null;
        const idx = this.args.stepIndex || 0;
        return this.route.steps[idx] || null;
    }

    get nextStep() {
        if (!this.route || !this.route.steps) return null;
        const idx = (this.args.stepIndex || 0) + 1;
        return this.route.steps[idx] || null;
    }

    get totalDistance() {
        return this.route?.totalDistance || 0;
    }

    get maneuverInfo() {
        if (!this.currentStep) return { icon: '\u2191', label: '' };
        return maneuverToIcon(this.currentStep.maneuverType, this.currentStep.modifier);
    }

    get currentStepDistance() {
        return this.args.stepRemainingDistance ?? this.currentStep?.distance ?? 0;
    }

    get currentStepDistanceLabel() {
        return formatDistance(this.currentStepDistance);
    }

    get remainingDistance() {
        return this.args.remainingDistance || 0;
    }

    get remainingDuration() {
        return this.args.remainingDuration || 0;
    }

    get traveledDistance() {
        return this.args.traveledDistance || 0;
    }

    get remainingDistanceLabel() {
        return formatDistance(this.remainingDistance);
    }

    get remainingDurationLabel() {
        return formatDuration(this.remainingDuration);
    }

    get progressPercent() {
        if (this.totalDistance === 0) return 0;
        return Math.min(100, Math.round((this.traveledDistance / this.totalDistance) * 100));
    }

    get nextStepLabel() {
        if (!this.nextStep) return null;
        const info = maneuverToIcon(this.nextStep.maneuverType, this.nextStep.modifier);
        return `${formatDistance(this.currentStepDistance)} 后${info.label}`;
    }

    @action cancel() {
        if (this.args.onCancel) {
            this.args.onCancel();
        }
    }
}
